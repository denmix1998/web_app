<?php
session_start();
require_once 'db.php';

$error = '';
$show_password_form = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $current_password = trim($_POST['current_password']);

    $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($current_password, $user['password'])) {
        $_SESSION['change_pass_user_id'] = $user['id'];
        $show_password_form = true;
    } else {
        $error = "Неверный логин или пароль!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if (!isset($_SESSION['change_pass_user_id'])) {
        $error = "Сессия устарела. Начните заново.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Пароли не совпадают!";
    } elseif (strlen($new_password) < 6) {
        $error = "Пароль должен быть не менее 6 символов!";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_password, $_SESSION['change_pass_user_id']]);

        unset($_SESSION['change_pass_user_id']);
        $success = "Пароль успешно изменён! <a href='login.php'>Войти</a>";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Смена пароля</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
        <h1>Смена пароля</h1>
        <a href="index.php" class="back-link">Назад</a>
    </div>

    <div class="container">
        <?php if (isset($success)): ?>
            <div class="alert success"><?= $success ?></div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$show_password_form): ?>
                <form method="POST" class="form">
                    <div class="form-group">
                        <label for="username">Логин:</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="current_password">Текущий пароль:</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <button type="submit" class="btn">Продолжить</button>
                </form>
            <?php else: ?>
                <form method="POST" class="form">
                    <div class="form-group">
                        <label for="new_password">Новый пароль:</label>
                        <input type="password" id="new_password" name="new_password" minlength="6" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Подтвердите пароль:</label>
                        <input type="password" id="confirm_password" name="confirm_password" minlength="6" required>
                    </div>
                    <button type="submit" class="btn">Изменить пароль</button>
                </form>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>
