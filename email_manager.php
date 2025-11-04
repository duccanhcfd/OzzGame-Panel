<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';
require_once __DIR__ . '/config.php';

// =================== XÁC ĐỊNH QUYỀN USER =================== //
$is_admin = !empty($_SESSION['user']);
$is_host = !empty($_SESSION['host_user']);
$current_domain = $is_host ? $_SESSION['host_domain'] : null;

// =================== LẤY DOMAIN TỪ HOSTS =================== //
if ($is_admin) {
    // Admin: lấy tất cả domain
    $domains = $pdo->query("SELECT domain FROM hosts ORDER BY domain ASC")
                   ->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Host user: chỉ lấy domain của mình
    $stmt = $pdo->prepare("SELECT domain FROM hosts WHERE domain = ?");
    $stmt->execute([$current_domain]);
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =================== XỬ LÝ FORM =================== //

// Tạo email
if (isset($_POST['add_email'])) {
    $user = trim($_POST['user']);
    $domain = $_POST['domain'];
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);

    // Kiểm tra quyền truy cập domain
    if (!$is_admin && $domain !== $current_domain) {
        echo "<p style='color:red'>❌ Bạn không có quyền tạo email cho domain này!</p>";
    } else if (!empty($user) && !empty($domain)) {
        $email = $user . '@' . $domain;
        $stmt = $pdo->prepare("INSERT INTO emails (email, password, domain) VALUES (?, ?, ?)");
        $stmt->execute([$email, $password, $domain]);

        echo "<p style='color:green'>✅ Đã tạo email <b>$email</b> thành công!</p>";
    }
}

// Đổi mật khẩu
if (isset($_POST['change_password'])) {
    $email = $_POST['email'];
    $newPassword = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
    
    // Kiểm tra quyền: lấy domain từ email
    $email_parts = explode('@', $email);
    $email_domain = count($email_parts) === 2 ? $email_parts[1] : '';
    
    if (!$is_admin && $email_domain !== $current_domain) {
        echo "<p style='color:red'>❌ Bạn không có quyền đổi mật khẩu cho email này!</p>";
    } else {
        $stmt = $pdo->prepare("UPDATE emails SET password=? WHERE email=?");
        $stmt->execute([$newPassword, $email]);

        echo "<p style='color:blue'>🔑 Đã đổi mật khẩu cho <b>$email</b></p>";
    }
}

// Xoá email
if (isset($_POST['delete_email'])) {
    $email = $_POST['delete_email'];
    
    // Kiểm tra quyền: lấy domain từ email
    $email_parts = explode('@', $email);
    $email_domain = count($email_parts) === 2 ? $email_parts[1] : '';
    
    if (!$is_admin && $email_domain !== $current_domain) {
        echo "<p style='color:red'>❌ Bạn không có quyền xoá email này!</p>";
    } else {
        $stmt = $pdo->prepare("DELETE FROM emails WHERE email=?");
        $stmt->execute([$email]);

        echo "<p style='color:red'>❌ Đã xoá email <b>$email</b></p>";
    }
}

// =================== LẤY DANH SÁCH EMAIL =================== //
if ($is_admin) {
    // Admin: lấy tất cả email
    $emailList = $pdo->query("SELECT email, domain FROM emails ORDER BY email ASC")
                     ->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Host user: chỉ lấy email thuộc domain của mình
    $stmt = $pdo->prepare("SELECT email, domain FROM emails WHERE domain = ? ORDER BY email ASC");
    $stmt->execute([$current_domain]);
    $emailList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Email Manager - ComZPanel</title>
<style>
body { font-family: Arial, sans-serif; margin:20px; }
fieldset { margin-bottom:20px; }
form.inline { display:inline; }
ul { list-style: none; padding: 0; }
li { margin-bottom: 8px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
details { margin: 5px 0; }
summary { cursor: pointer; color: #007BFF; }
button { margin-left: 5px; }
</style>
</head>
<body>
<h1>Email Manager - ComZPanel</h1>
<p>Đang đăng nhập với tư cách: <strong><?php echo $is_admin ? 'Admin' : 'Host User (' . htmlspecialchars($current_domain) . ')'; ?></strong></p>

<!-- Form tạo email -->
<fieldset>
<legend>Tạo Email</legend>
<form method="post">
    User: <input type="text" name="user" placeholder="info" required>@
    <select name="domain" <?php echo !$is_admin ? 'disabled' : ''; ?>>
        <?php foreach($domains as $d): ?>
            <option value="<?=htmlspecialchars($d['domain'])?>" <?php echo (!$is_admin && $d['domain'] === $current_domain) ? 'selected' : ''; ?>>
                <?=htmlspecialchars($d['domain'])?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if (!$is_admin): ?>
        <input type="hidden" name="domain" value="<?=htmlspecialchars($current_domain)?>">
    <?php endif; ?>
    <br><br>
    Mật khẩu: <input type="password" name="password" required><br><br>
    <button type="submit" name="add_email">Tạo Email</button>
</form>
</fieldset>

<!-- Danh sách email -->
<fieldset>
<legend>Danh sách Email</legend>
<ul>
<?php if (count($emailList) > 0): ?>
    <?php foreach($emailList as $e): ?>
        <li>
            <strong><?=htmlspecialchars($e['email'])?></strong> (<?=htmlspecialchars($e['domain'])?>)
            <!-- Nút xoá -->
            <form method="post" class="inline">
                <input type="hidden" name="delete_email" value="<?=htmlspecialchars($e['email'])?>">
                <button type="submit" onclick="return confirm('Xoá email <?=htmlspecialchars($e['email'])?> ?')">Xoá</button>
            </form>

            <!-- Đổi mật khẩu -->
            <details>
                <summary>Đổi mật khẩu</summary>
                <form method="post">
                    <input type="hidden" name="email" value="<?=htmlspecialchars($e['email'])?>">
                    Mật khẩu mới: <input type="password" name="new_password" required>
                    <button type="submit" name="change_password">Đổi</button>
                </form>
            </details>
        </li>
    <?php endforeach; ?>
<?php else: ?>
    <li><i>Chưa có email nào.</i></li>
<?php endif; ?>
</ul>
</fieldset>

</body>
</html>
<?php include __DIR__ . '/footer.php'; ?>