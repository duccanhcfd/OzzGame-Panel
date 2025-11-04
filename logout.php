<?php
// logout.php

// Bắt buộc phải start session trước khi hủy
session_start();

// Xóa toàn bộ biến session
$_SESSION = [];

// Nếu có sử dụng cookie cho session thì xóa cookie đó luôn
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Hủy session
session_destroy();

// Quay về trang login
header('Location: /login.php');
exit;