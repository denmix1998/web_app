<?php
session_start();
require_once 'db.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$isAdmin = ($_SESSION['role'] === 'admin');
$successMsg = '';
$errorMsg = '';

$consumables = $pdo->query("SELECT id, material_name, quantity FROM consumables_list ORDER BY material_name")->fetchAll();
$departments = $pdo->query("SELECT DISTINCT name FROM departament ORDER BY name")->fetchAll();
$objects = $pdo->query("SELECT DISTINCT name FROM object ORDER BY name")->fetchAll();

$summary = $pdo->query("
    SELECT 
        material_name, 
        SUM(quantity) as quantity, 
        MAX(record_date) as record_date 
    FROM consumables_list 
    GROUP BY material_name 
    ORDER BY material_name
")->fetchAll();

if (isset($_POST['add_consumable']) && $isAdmin) {
    $consumable_id = (int)$_POST['consumable_id'];
    $add_qty = (int)$_POST['quantity'];
    $comment = $_POST['comment'] ?? '';

    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("SELECT id, material_name, quantity FROM consumables_list WHERE id = ? FOR UPDATE");
        $stmt->execute([$consumable_id]);
        $material = $stmt->fetch();
        
        if (!$material) {
            throw new Exception("Картридж не найден!");
        }

        $newQuantity = $material['quantity'] + $add_qty;

        $updateStmt = $pdo->prepare("UPDATE consumables_list SET quantity = ?, record_date = NOW() WHERE id = ?");
        $updateStmt->execute([$newQuantity, $consumable_id]);
        
        if ($updateStmt->rowCount() === 0) {
            throw new Exception("Не удалось обновить запись!");
        }

        $action = "Добавлен картридж: {$material['material_name']} (ID:{$material['id']}) Новый остаток: $newQuantity";
        if (!empty($comment)) {
            $action .= ". Комментарий: {$comment}";
        }

        $logStmt = $pdo->prepare("INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())");
        $logStmt->execute([$_SESSION['user_id'], $action]);

        $pdo->commit();
        $_SESSION['success_msg'] = "Успешно! Текущий остаток: $newQuantity";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = "Ошибка: " . $e->getMessage();
    }
    
    header("Location: index.php");
    exit();
}

if (isset($_POST['action']) && $_POST['action'] === 'issue_cartridge' && $isAdmin) {
    // Получаем данные с проверкой
    $fio = trim($_POST['full_name'] ?? '');
    $department = trim($_POST['departament'] ?? '');
    $object = trim($_POST['location'] ?? '');
    $modelId = (int)($_POST['model'] ?? 0);
    $date = $_POST['date'] ?? '';
    $qty = (int)($_POST['quantity'] ?? 0);

    // Валидация
    if (empty($fio) || empty($department) || empty($object) || empty($date) || $qty <= 0) {
        $_SESSION['error_msg'] = "Все обязательные поля должны быть заполнены!";
        header("Location: index.php");
        exit();
    }

    // Проверяем, есть ли введённые подразделения и объекты в базе, если нет - добавляем их
    try {
        // Проверка/Добавление подразделения
        if (!empty($department)) {
            $stmt = $pdo->prepare("SELECT id FROM departament WHERE name = ?");
            $stmt->execute([$department]);
            if ($stmt->rowCount() === 0) {
                // Если нет, добавляем новое подразделение
                $stmt = $pdo->prepare("INSERT INTO departament (name) VALUES (?)");
                $stmt->execute([$department]);
            }
        }

        // Проверка/Добавление объекта
        if (!empty($object)) {
            $stmt = $pdo->prepare("SELECT id FROM object WHERE name = ?");
            $stmt->execute([$object]);
            if ($stmt->rowCount() === 0) {
                // Если нет, добавляем новый объект
                $stmt = $pdo->prepare("INSERT INTO object (name) VALUES (?)");
                $stmt->execute([$object]);
            }
        }

        // Начинаем транзакцию
        $pdo->beginTransaction();

        // Проверка картриджа
        $stmt = $pdo->prepare("SELECT id, material_name, quantity FROM consumables_list WHERE id = ? FOR UPDATE");
        $stmt->execute([$modelId]);
        $cartridge = $stmt->fetch();

        if (!$cartridge) {
            throw new Exception("Картридж не найден!");
        }

        if ($cartridge['quantity'] < $qty) {
            throw new Exception("Недостаточно картриджей (доступно: {$cartridge['quantity']})!");
        }

        // Вставка данных в таблицу выдачи
        $insertStmt = $pdo->prepare("
            INSERT INTO cartridges 
            (full_name, department, location, model, date, quantity)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([$fio, $department, $object, $cartridge['material_name'], $date, $qty]);

        // Обновление остатков картриджей
        $updateStmt = $pdo->prepare("UPDATE consumables_list SET quantity = quantity - ? WHERE id = ?");
        $updateStmt->execute([$qty, $modelId]);

        // Логирование действия
        $logStmt = $pdo->prepare("INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())");
        $logStmt->execute([$_SESSION['user_id'], "Выдал картридж {$cartridge['material_name']} для $fio, количество: $qty"]);

        // Коммит транзакции
        $pdo->commit();
        $_SESSION['success_msg'] = "Картридж успешно выдан!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = "Ошибка: " . $e->getMessage();
    }

    // Перенаправление обратно на главную страницу
    header("Location: index.php");
    exit();
}


// Фильтрация для таблицы выданных картриджей
$where = [];
$params = [];
$orderBy = 'date DESC';

if (!empty($_GET['filter_fio'])) {
    $where[] = 'full_name LIKE ?';
    $params[] = '%' . $_GET['filter_fio'] . '%';
}
if (!empty($_GET['filter_department'])) {
    $where[] = 'department LIKE ?';
    $params[] = '%' . $_GET['filter_department'] . '%';
}
if (!empty($_GET['filter_model'])) {
    $where[] = 'model LIKE ?';
    $params[] = '%' . $_GET['filter_model'] . '%';
}
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $where[] = 'date BETWEEN ? AND ?';
    $params[] = $_GET['start_date'];
    $params[] = $_GET['end_date'];
}

if (!empty($_GET['sort'])) {
    $allowed = ['full_name', 'department', 'location', 'model', 'date', 'quantity'];
    if (in_array($_GET['sort'], $allowed)) {
        $orderBy = $_GET['sort'] . ' ASC';
    }
}

$sql = 'SELECT * FROM cartridges';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ' . $orderBy;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>
        Учёт картриджей</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- Выезжающее меню -->
    <div class="menu">
        <a href=""></a>
        <a href="logs.php">Посмотреть логи действий</a>
        <a href="summary.php" target="_blank">Посмотреть статистику</a>
        <a href="new_cart.php">Добавить модель картриджа</a>
        <a href="change_password.php">Изменить пароль</a>
    </div>

<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>

    <!-- Кнопка меню -->
    <div class="menu-btn" onclick="toggleMenu()">☰ Меню</div>

<?php endif; ?>

    <!-- Шапка с кнопкой выхода -->
    <div class="header">
        <a href="logout.php">Выйти</a>
    </div>
    <h1>ㅤ</h1>
    <h1>Добро пожаловать, <?= htmlspecialchars($_SESSION['user']) ?></h1>

    <?php if ($successMsg): ?>
        <p class="success"><?= $successMsg ?></p>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <p class="error"><?= $errorMsg ?></p>
    <?php endif; ?>

    <div class="form-block">
    <h2>Фильтрация выданных картриджей</h2>
    <form method="get">
        <input type="text" name="filter_fio" placeholder="ФИО" value="<?= htmlspecialchars($_GET['filter_fio'] ?? '') ?>">
        
        <select name="filter_department">
            <option value="">Все подразделения</option>
            <?php foreach ($departments as $dep): ?>
                <option value="<?= htmlspecialchars($dep['name']) ?>" <?= ($_GET['filter_department'] ?? '') == $dep['name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($dep['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="filter_model">
            <option value="">Все модели</option>
            <?php foreach ($consumables as $c): ?>
                <option value="<?= htmlspecialchars($c['material_name']) ?>" <?= ($_GET['filter_model'] ?? '') == $c['material_name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['material_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
        <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
        
        <button type="submit">Применить фильтр</button>
        </form>
    </div>
    
    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    
    <div class="form-block">
    <h2>Выдача картриджа</h2>
    <form method="post">
        <!-- Поле ФИО -->
        <input type="text" name="full_name" placeholder="ФИО получателя" required>

       <!-- Подразделение -->
<div class="inline-group">
    <select name="departament" id="departament" required>
        <option value="">Выберите подразделение</option>
        <?php foreach ($departments as $dep): ?>
            <option value="<?= htmlspecialchars($dep['name']) ?>">
                <?= htmlspecialchars($dep['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="text" id="new_departament" name="new_departament" placeholder="Новое подразделение" style="display:none;">
    <button type="button" onclick="toggleInput('departament')">Добавить подразделение</button>
</div>

<!-- Объект -->
<div class="inline-group">
    <select name="location" id="location" required>
        <option value="">Выберите объект</option>
        <?php foreach ($objects as $obj): ?>
            <option value="<?= htmlspecialchars($obj['name']) ?>">
                <?= htmlspecialchars($obj['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="text" id="new_location" name="new_location" placeholder="Новый объект" style="display:none;">
    <button type="button" onclick="toggleInput('location')">Добавить объект</button>
</div>


        <!-- Выбор картриджа -->
        <select name="model" required>
            <option value="">Выберите картридж</option>
            <?php foreach ($consumables as $c): ?>
                <?php if (isset($c['quantity']) && $c['quantity'] !== null): ?>
                    <option value="<?= $c['id'] ?>">
                        <?= htmlspecialchars($c['material_name']) ?> (Остаток: <?= $c['quantity'] ?>)
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        
        <input type="hidden" name="action" value="issue_cartridge">
        
        <!-- Дата выдачи -->
        <input type="date" name="date" required>
        
        <!-- Количество -->
        <input type="number" name="quantity" placeholder="Количество" min="1" required>
        
        <!-- Кнопка отправки -->
        <button type="submit" name="issue_cartridge">Выдать</button>
        </form>
    </div>
    
    <script>
        // Функция для показа/скрытия выезжающего меню
        function toggleMenu() {
            const menu = document.querySelector('.menu');
            if (menu.style.left === '0px') {
                menu.style.left = '-250px'; // Скрыть меню
            } else {
                menu.style.left = '0'; // Показать меню
            }
        }

        // Функция для показа/скрытия полей ввода для новых значений
        function toggleInput(type) {
            var newField = document.getElementById('new_' + type);
            var selectField = document.getElementById(type);

            if (newField.style.display === "none") {
                newField.style.display = "block";
                selectField.style.display = "none";
            } else {
                newField.style.display = "none";
                selectField.style.display = "inline";
            }
        }
    </script>
    
    <div class="form-block">
    <h2>Поступление картриджа</h2>
    <form method="post">
        <select name="consumable_id" required>
            <option value="">Выберите картридж</option>
            <?php foreach ($consumables as $c): ?>
                <option value="<?= $c['id'] ?>">
                    <?= htmlspecialchars($c['material_name']) ?> (ID:<?= $c['id'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
        
        <input type="number" name="quantity" placeholder="Количество" min="1" required>
        
        <input type="hidden" name="action" value="receive_cartridge">
        
        <button type="submit" name="receive_cartridge">Принять</button>
        </form>
    </div>
    
    <?php endif; ?>
    
    <h2 style="text-align: center;">Список выданных картриджей</h2>
<table>
    <thead>
        <tr>
            <th>ФИО</th>
            <th>Подразделение</th>
            <th>Объект</th>
            <th>Модель</th>
            <th>Дата</th>
            <th>Количество</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($cartridges as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['fio']) ?></td>
                <td><?= htmlspecialchars($row['department']) ?></td>
                <td><?= htmlspecialchars($row['location']) ?></td>
                <td><?= htmlspecialchars($row['model']) ?></td>
                <td><?= htmlspecialchars($row['date']) ?></td>
                <td><?= htmlspecialchars($row['quantity']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
    
    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    
   <h2 style="text-align: center;">
    <a href="#" onclick="
        const el = document.getElementById('summary');
        el.style.display = (el.style.display === 'none') ? 'block' : 'none';
        return false;" style="font-size: 40px;">
        Показать / скрыть остаток по картриджам
    </a>
</h2>

<div id="summary" class="summary-table" style="display: none;">
    <table border="1">
        <tr><th>Картриджи</th><th>Количество</th><th>Обновлено</th></tr>
        <?php foreach ($summary as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['material_name']) ?></td>
                <td><?= $row['quantity'] ?></td>
                <td><?= date('d.m.Y H:i', strtotime($row['record_date'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
    
        <script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('commentModal');
        const closeBtn = document.querySelector('.close');
        let currentForm = null;

        closeBtn.onclick = function() {
            modal.style.display = 'none';
            document.getElementById('commentText').value = '';
        }

        document.getElementById('skipComment').onclick = function() {
            modal.style.display = 'none';
            document.getElementById('commentText').value = '';
            if (currentForm) {
                document.getElementById('operationComment').value = '';
                currentForm.submit();
            }
        }

        document.getElementById('saveComment').onclick = function() {
            const comment = document.getElementById('commentText').value;
            document.getElementById('operationComment').value = comment;
            modal.style.display = 'none';
            if (currentForm) {
                currentForm.submit();
            }
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = 'none';
                document.getElementById('commentText').value = '';
            }
        }
    });

    function showCommentModal(formId) {
        currentForm = document.getElementById(formId);
        document.getElementById('commentModal').style.display = 'block';
        return false;
    }
    </script>
    
    <?php endif; ?>
    
    <h1>ㅤ</h1>
    <h1>ㅤ</h1>
</body>
</html>
