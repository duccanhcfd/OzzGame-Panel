<?php
// action.php

$host = 'localhost';
$db   = 'comzpanel';
$user = 'comzpanel_user';
$pass = 'comzpanel@';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO($dsn, $user, $pass, $options);

// Lấy command và ID từ URL
$cmd = $_GET['cmd'] ?? '';
$id  = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM vps WHERE id=?");
$stmt->execute([$id]);
$vps = $stmt->fetch();

if(!$vps){
    die("VPS không tồn tại.");
}

// Container info
$name = $vps['name'];
$ram  = $vps['ram'];
$cpu  = $vps['cpu'];
$ssh_port = $vps['port_ssh'];
$web_port = $vps['port_web'];
$os   = $vps['os'];

// Chọn image theo OS
if(strtolower($os) == 'ubuntu'){
    $image = "rastasheep/ubuntu-sshd:20.04";
} else {
    $image = "almalinux:ssh"; // cần tạo image AlmaLinux SSH trước
}

switch($cmd){
    case 'start':
        exec("docker start $name 2>&1", $out, $ret);
        if($ret===0) $pdo->prepare("UPDATE vps SET status='running' WHERE id=?")->execute([$id]);
        break;
    case 'stop':
        exec("docker stop $name 2>&1", $out, $ret);
        if($ret===0) $pdo->prepare("UPDATE vps SET status='stopped' WHERE id=?")->execute([$id]);
        break;
    case 'delete':
        exec("docker rm -f $name 2>&1", $out, $ret);
        if($ret===0) $pdo->prepare("DELETE FROM vps WHERE id=?")->execute([$id]);
        break;
    case 'reinstall':
        // Xóa container cũ
        exec("docker rm -f $name 2>&1");
        // Tạo lại container
        $cmd_run = "docker run -d --name $name --memory='$ram' --cpus='$cpu' -p $ssh_port:22 -p $web_port:80 $image";
        exec($cmd_run . ' 2>&1', $out, $ret);
        if($ret===0){
            $pdo->prepare("UPDATE vps SET status='running' WHERE id=?")->execute([$id]);
        }
        break;
    default:
        die("Command không hợp lệ.");
}

header("Location: listvps.php");
exit;
?>