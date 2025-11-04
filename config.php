<?php
$host = 'localhost';
$db = 'comzpanel';
$user = 'comzpanel_user';
$pass = 'comzpanel@';
try {
            $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
                        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
            die("Kết nối database thất bại: " . $e->getMessage());
}
?>
