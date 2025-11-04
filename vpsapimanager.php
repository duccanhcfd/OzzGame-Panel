<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_login(); // Ép admin đăng nhập
require_once __DIR__ . '/header.php';

function generateKey($length = 32) {
    return bin2hex(random_bytes($length));
}

$msg = '';

// Tạo API key
if (isset($_POST['create'])) {
    $key = generateKey();
    $stmt = $pdo->prepare("INSERT INTO vpsapi_key (api_key, user_id, status) VALUES (?, ?, 'active')");
    $stmt->execute([$key, $_POST['user_id'] ?? 1]);
    $msg = "<b>API Key tạo thành công:</b> $key";
}

// Đình chỉ key
if (isset($_POST['disable'])) {
    $stmt = $pdo->prepare("UPDATE vpsapi_key SET status='disabled' WHERE id=?");
    $stmt->execute([$_POST['id']]);
    $msg = "API Key đã đình chỉ.";
}

// Xóa key
if (isset($_POST['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM vpsapi_key WHERE id=?");
    $stmt->execute([$_POST['id']]);
    $msg = "API Key đã xóa.";
}

// Hiển thị danh sách key
$stmt = $pdo->query("SELECT * FROM vpsapi_key ORDER BY id DESC");
$keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quản lý VPS API Key</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="container py-4">
    <h2>Quản lý VPS API Key</h2>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-info"><?= $msg ?></div>
    <?php endif; ?>

    <form method="POST" class="mb-3">
        <div class="input-group">
            <span class="input-group-text">User ID</span>
            <input type="text" name="user_id" value="1" class="form-control">
            <button name="create" class="btn btn-success">Tạo API Key</button>
        </div>
    </form>

    <h3>Danh sách API Key</h3>
    <table class="table table-bordered">
        <thead>
            <tr><th>ID</th><th>Key</th><th>Status</th><th>Hành động</th></tr>
        </thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
            <tr>
                <td><?= htmlspecialchars($k['id']) ?></td>
                <td><?= htmlspecialchars($k['api_key']) ?></td>
                <td><?= htmlspecialchars($k['status']) ?></td>
                <td>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="id" value="<?= $k['id'] ?>">
                        <button name="disable" class="btn btn-warning btn-sm">Đình chỉ</button>
                    </form>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="id" value="<?= $k['id'] ?>">
                        <button name="delete" class="btn btn-danger btn-sm">Xóa</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
<?php require_once __DIR__ . '/footer.php'; ?>