<?php
$host = 'localhost';
$dbname = 'g904477m_app';
$username = 'g904477m_app';
$password = 'r!O5mM3*hmP&';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>