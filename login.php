<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

// --- Cấu hình khóa IP ---
$max_attempts = 5;         // Số lần đăng nhập thất bại tối đa
$attempt_window = 600;     // Khoảng thời gian tính bằng giây (10 phút)
$lock_time = 900;          // Thời gian khóa IP bằng giây (15 phút)
$ip = $_SERVER['REMOTE_ADDR'];

// Tạo bảng failed_logins nếu chưa có
$pdo->exec("
CREATE TABLE IF NOT EXISTS failed_logins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    attempt_time INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");

$error = '';

// --- Kiểm tra IP đã bị khóa chưa ---
$stmt = $pdo->prepare("SELECT COUNT(*) AS fail_count, MAX(attempt_time) AS last_attempt 
                       FROM failed_logins 
                       WHERE ip = ? AND attempt_time > ?");
$stmt->execute([$ip, time() - $attempt_window]);
$fail_data = $stmt->fetch(PDO::FETCH_ASSOC);

$fail_count = (int)$fail_data['fail_count'];
$last_attempt = (int)$fail_data['last_attempt'];

if ($fail_count >= $max_attempts && (time() - $last_attempt) < $lock_time) {
    $error = "IP của bạn đã bị khóa tạm thời do đăng nhập thất bại quá nhiều lần. Vui lòng thử lại sau.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.';
    } else {
        try {



            // 1. Thử đăng nhập admin
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && password_verify($password, $user['password_hash'])) {
    // Đăng nhập admin thành công
    $pdo->prepare("DELETE FROM failed_logins WHERE ip = ?")->execute([$ip]);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = $user['username'];
    header('Location: /index.php');
    exit;
}

// 2. Thử đăng nhập host
$stmt = $pdo->prepare("SELECT * FROM hosts WHERE username = ? AND password = ? LIMIT 1");
$stmt->execute([$username, $password]); // host pass lưu plain
$host = $stmt->fetch(PDO::FETCH_ASSOC);

if ($host) {
    // Đăng nhập host thành công
    $pdo->prepare("DELETE FROM failed_logins WHERE ip = ?")->execute([$ip]);
    $_SESSION['host_user']   = $host['username'];
    $_SESSION['host_domain'] = $host['domain'];
    header('Location: /filemanager.php'); // host chỉ cần vào file manager
    exit;
}

// Nếu cả 2 đều fail
$error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
$stmt = $pdo->prepare("INSERT INTO failed_logins (ip, attempt_time) VALUES (?, ?)");
$stmt->execute([$ip, time()]);




        } catch (PDOException $e) {
            $error = 'Lỗi kết nối database: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đăng nhập - OccPanel</title>
<link rel="stylesheet" href="/style.css">
<style>
    /* CSS giống như cũ */
    body {
        margin: 0;
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu;
        background: linear-gradient(135deg, #0b1220, #1f2c45);
        color: #e7ecfa;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 16px;
        box-sizing: border-box;
    }
    .login-card {
        background: #111b2d;
        padding: 40px 50px;
        border-radius: 12px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.6);
        width: 360px;
        max-width: 100%;
        box-sizing: border-box;
    }
    .login-card h1 {
        margin-bottom: 24px;
        font-size: 24px;
        text-align: center;
        color: #3b82f6;
    }
    .login-card label {
        display: block;
        margin-bottom: 12px;
        font-weight: bold;
        font-size: 14px;
    }
    .login-card input {
        width: 100%;
        padding: 10px 12px;
        border-radius: 6px;
        border: 1px solid #1e2a44;
        background: #0b1220;
        color: #e7ecfa;
        margin-top: 4px;
        box-sizing: border-box;
    }
    .login-card button {
        width: 100%;
        padding: 12px;
        margin-top: 20px;
        border: none;
        border-radius: 6px;
        background-color: #3b82f6;
        color: #fff;
        font-size: 16px;
        cursor: pointer;
        transition: background 0.3s;
    }
    .login-card button:hover {
        background-color: #2563eb;
    }
    .error-box {
        background: #ffeded;
        color: #d32f2f;
        padding: 10px;
        border-radius: 6px;
        margin-bottom: 16px;
        font-size: 14px;
    }
    @media (max-width: 480px) {
        .login-card {
            padding: 30px 20px;
        }
        .login-card h1 {
            font-size: 20px;
        }
        .login-card input, .login-card button {
            padding: 10px;
            font-size: 14px;
        }
    }
</style>
</head>
<body>
<div class="login-card">
    <h1>OccPanel</h1>
    <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login.php">
        <label>Tài khoản
            <input type="text" name="username" required autofocus>
        </label>
        <label>Mật khẩu
            <input type="password" name="password" required>
        </label>
        <button type="submit">Đăng nhập</button>
    </form>
</div>
</body>
</html>