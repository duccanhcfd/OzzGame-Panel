<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/auth.php';
require_login(); // Kiểm tra login trước khi cho phép đổi
require_once __DIR__ . '/config.php';

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_username = trim($_POST['current_username'] ?? '');
    $new_username     = trim($_POST['new_username'] ?? '');
    $new_password     = $_POST['new_password'] ?? '';

    if (!$current_username || !$new_username || !$new_password) {
        $error = 'Vui lòng điền đầy đủ thông tin.';
    } else {
        try {
            // Kiểm tra user hiện tại
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$current_username]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = "User hiện tại không tồn tại.";
            } else {
                // Hash mật khẩu mới
                $hash = password_hash($new_password, PASSWORD_DEFAULT);

                // Cập nhật username + password
                $update = $pdo->prepare("UPDATE users SET username = ?, password_hash = ? WHERE id = ?");
                $update->execute([$new_username, $hash, $user['id']]);

                $msg = "✅ Đã cập nhật user '$current_username' thành '$new_username'.";
            }
        } catch (PDOException $e) {
            $error = "Lỗi database: " . $e->getMessage();
        }
    }
}
?>

<?php include __DIR__ . '/header.php'; ?>

<section style="padding:20px; max-width:480px; margin:auto;">
    <h1>🔑 Đổi Tài khoản / Mật khẩu</h1>

    <?php if ($msg): ?>
        <div style="background:#d4edda;color:#155724;padding:10px;border-radius:6px;margin-bottom:16px;">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background:#ffeded;color:#d32f2f;padding:10px;border-radius:6px;margin-bottom:16px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label>Username hiện tại
            <input type="text" name="current_username" required>
        </label>
        <label>Username mới
            <input type="text" name="new_username" required>
        </label>
        <label>Mật khẩu mới
            <input type="password" name="new_password" required>
        </label>
        <button type="submit" style="margin-top:12px;">Cập nhật</button>
    </form>
</section>

<?php include __DIR__ . '/footer.php'; ?>