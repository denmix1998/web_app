<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_cartridge'])) {
    $modelDevice = trim($_POST['model_device']);
    $materialName = trim($_POST['material_name']);
    $quantity = (int)$_POST['quantity'];
    $recordDate = $_POST['record_date'] ?? date('Y-m-d');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO consumables_list 
            (model_device, material_name, quantity, record_date) 
            VALUES (:model, :material, :qty, :date)
        ");
        $stmt->execute([
            ':model' => $modelDevice,
            ':material' => $materialName,
            ':qty' => $quantity,
            ':date' => $recordDate
        ]);

        $action = "Добавлен новый картридж: $materialName (Модель: $modelDevice) - $quantity шт.";
        $logStmt = $pdo->prepare("INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())");
        $logStmt->execute([$_SESSION['user_id'], $action]);

        $pdo->commit();
        $_SESSION['success_msg'] = "Картридж успешно добавлен!";
        header("Location: new_cart.php");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = "Ошибка при добавлении картриджа: " . $e->getMessage();
        header("Location: new_cart.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавление нового картриджа</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
        <h1>Добавление нового картриджа</h1>
            <a href="index.php">Назад</a>
        </div>
    </div>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert success"><?= $_SESSION['success_msg'] ?></div>
        <?php unset($_SESSION['success_msg']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert error"><?= $_SESSION['error_msg'] ?></div>
        <?php unset($_SESSION['error_msg']); ?>
    <?php endif; ?>

    <form method="post" class="cartridge-form">
        <div class="form-group">
            <label for="model_device">Модель устройства:</label>
            <input type="text" id="model_device" name="model_device" required>
        </div>

        <div class="form-group">
            <label for="material_name">Название картриджа:</label>
            <input type="text" id="material_name" name="material_name" required>
        </div>

        <div class="form-group">
            <label for="quantity">Количество:</label>
            <input type="number" id="quantity" name="quantity" min="1" value="1" required>
        </div>

        <div class="form-group">
            <label for="record_date">Дата:</label>
            <input type="date" id="record_date" name="record_date" value="<?= date('Y-m-d') ?>">
        </div>

        <button type="submit" name="add_cartridge" class="submit-btn">Добавить картридж</button>
    </form>
</body>
</html>
