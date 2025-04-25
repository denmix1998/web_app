<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// Получаем максимальную дату для фильтра "Сегодня" по умолчанию
$defaultDate = date('Y-m-d');

// Обработка фильтров
$where = [];
$params = [];
$orderBy = 'created_at DESC';

// Фильтр по пользователю
if (!empty($_GET['username'])) {
    $where[] = "u.username LIKE ?";
    $params[] = "%{$_GET['username']}%";
}

// Фильтр по дате
if (!empty($_GET['date_filter'])) {
    switch ($_GET['date_filter']) {
        case 'today':
            $where[] = "DATE(l.created_at) = ?";
            $params[] = $defaultDate;
            break;
        case 'yesterday':
            $where[] = "DATE(l.created_at) = DATE_SUB(?, INTERVAL 1 DAY)";
            $params[] = $defaultDate;
            break;
        case 'week':
            $where[] = "l.created_at >= DATE_SUB(?, INTERVAL 1 WEEK)";
            $params[] = $defaultDate;
            break;
        case 'month':
            $where[] = "l.created_at >= DATE_SUB(?, INTERVAL 1 MONTH)";
            $params[] = $defaultDate;
            break;
    }
}

// Ручной диапазон дат
if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
    $where[] = "DATE(l.created_at) BETWEEN ? AND ?";
    $params[] = $_GET['start_date'];
    $params[] = $_GET['end_date'];
}

// Сборка запроса
$sql = "SELECT 
            l.*, 
            u.username,
            DATE_FORMAT(l.created_at, '%d.%m.%Y %H:%i:%s') AS formatted_date
        FROM logs l
        LEFT JOIN users u ON l.user_id = u.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY $orderBy LIMIT 500"; // Ограничение для производительности

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Журнал действий</title>
        <link rel="stylesheet" href="style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .filter-section { margin-bottom: 20px; padding: 15px; background: #f5f5f5; }
        .filter-section label { margin-right: 10px; }
        .back-link { display: inline-block; margin-top: 15px; }
    </style>
</head>
<body>
        <a href="index.php" class="back-link">← Назад</a>
    <h2>Журнал действий пользователей</h2>
    
    <div class="filter-section">
        <form method="GET">
            <input type="text" name="username" placeholder="Имя пользователя" value="<?= htmlspecialchars($_GET['username'] ?? '') ?>">
            
            <label for="date_filter">Период:</label>
            <select name="date_filter" id="date_filter">
                <option value="">Все</option>
                <option value="today" <?= ($_GET['date_filter'] ?? '') == 'today' ? 'selected' : '' ?>>Сегодня</option>
                <option value="yesterday" <?= ($_GET['date_filter'] ?? '') == 'yesterday' ? 'selected' : '' ?>>Вчера</option>
                <option value="week" <?= ($_GET['date_filter'] ?? '') == 'week' ? 'selected' : '' ?>>Неделя</option>
                <option value="month" <?= ($_GET['date_filter'] ?? '') == 'month' ? 'selected' : '' ?>>Месяц</option>
            </select>
            
            <span style="margin-left: 15px;">Или укажите диапазон:</span>
            <input type="date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
            <span>до</span>
            <input type="date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
            
            <button type="submit" style="margin-left: 15px;">Применить фильтр</button>
            <a href="logs.php" style="margin-left: 10px;">Сбросить</a>
        </form>
    </div>
    
    <table>
        <tr>
            <th>Дата и время</th>
            <th>Пользователь</th>
            <th>Действие</th>
        </tr>
        <?php if (empty($logs)): ?>
            <tr><td colspan="3">Нет записей логов</td></tr>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['formatted_date']) ?></td>
                    <td><?= htmlspecialchars($log['username'] ?? 'Система') ?></td>
                    <td><?= nl2br(htmlspecialchars($log['action'])) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
</body>
</html>
