<?php
session_start();
require_once 'db.php';

$error = '';
$show_password_form = false; // Показывать ли форму смены пароля?

// Если форма с логином/паролем отправлена
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $current_password = trim($_POST['current_password']);

    // Проверяем, есть ли такой пользователь
    $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($current_password, $user['password'])) {
        // Данные верны, запоминаем ID пользователя в сессии
        $_SESSION['change_pass_user_id'] = $user['id'];
        $show_password_form = true;
    } else {
        $error = "Неверный логин или пароль!";
    }
}

// Если форма с новым паролем отправлена
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    // Проверяем, что пользователь подтвержден
    if (!isset($_SESSION['change_pass_user_id'])) {
        $error = "Сессия устарела. Начните заново.";
    } 
    // Проверяем совпадение паролей
    elseif ($new_password !== $confirm_password) {
        $error = "Пароли не совпадают!";
    } 
    // Проверяем длину пароля
    elseif (strlen($new_password) < 6) {
        $error = "Пароль должен быть не менее 6 символов!";
    } 
    // Если всё ок, обновляем пароль
    else {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_password, $_SESSION['change_pass_user_id']]);

        // Очищаем сессию и выводим успех
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
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="password-form">
        <h1>Смена пароля</h1>

        <?php if (isset($success)): ?>
            <div class="success"><?= $success ?></div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$show_password_form): ?>
                <!-- Форма ввода логина и текущего пароля -->
                <form method="POST">
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
                <!-- Форма ввода нового пароля -->
                <form method="POST">
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
