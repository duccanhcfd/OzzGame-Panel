<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

require_once __DIR__ . '/config.php'; // để có $pdo

// ================== LOGIN CHECK ==================
function require_login(): void {
    if (empty($_SESSION['user_id']) && empty($_SESSION['host_user'])) {
        header('Location: /login.php');
        exit;
    }
}

// ================== CURRENT USER ==================
function current_user() {
    if (!empty($_SESSION['user'])) {
        return $_SESSION['user']; // admin
    } elseif (!empty($_SESSION['host_user'])) {
        return $_SESSION['host_user']; // host
    }
    return null;
}

// ================== LOGIN FUNCTION ==================
function login($username, $password): bool {
    global $pdo;

    // 1. Thử check admin users
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = $user['username'];
        return true;
    }

    // 2. Thử check hosts
    $stmt = $pdo->prepare("SELECT * FROM hosts WHERE username=? AND password=? LIMIT 1");
    $stmt->execute([$username, $password]); // host pass đang lưu plain
    $host = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($host) {
        $_SESSION['host_user'] = $host['username'];
        $_SESSION['host_domain'] = $host['domain'];
        return true;
    }

    return false;
}

// ================== LOGOUT ==================
function logout(): void {
    session_unset();
    session_destroy();
    header("Location: /login.php");
    exit;
}