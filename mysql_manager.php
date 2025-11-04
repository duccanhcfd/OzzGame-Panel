<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
$msg = '';

require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

// ================== CONFIG ==================
require_once __DIR__ . '/config.php';  // load PDO từ config

// Lấy username đang đăng nhập
$owner = current_user();

// ====== Xóa database + user liên quan ======
if (isset($_GET['del_db'])) {
    $db_name = preg_replace('/[^a-zA-Z0-9_]/','',$_GET['del_db']);

    // chỉ cho phép xóa DB có prefix db_owner_
    if (strpos($db_name, "db_{$owner}_") === 0) {
        try {
            $pdo->exec("DROP DATABASE `$db_name`");
            $user = str_replace('db_','',$db_name);
            // xóa user trùng tên db nếu có
            $stmt = $pdo->query("SELECT User FROM mysql.user WHERE User='$user'");
            if ($stmt->fetch()) {
                $pdo->exec("DROP USER '$user'@'localhost'");
            }
            $pdo->exec("FLUSH PRIVILEGES");
            $msg = "🗑️ Đã xóa database `$db_name` và user `$user` nếu có";
        } catch (PDOException $e) {
            $msg = "❌ Lỗi xóa DB: " . $e->getMessage();
        }
    } else {
        $msg = "❌ Bạn không có quyền xóa DB này.";
    }
}

// ====== Tạo user + database khách ======
if (isset($_POST['create_client'])) {
    $client_name = preg_replace('/[^a-zA-Z0-9_]/','',$_POST['client_name']);
    $client_pass = $_POST['client_pass'];

    if ($client_name && $client_pass) {
        // prefix theo user đang login
        $db_name = 'db_' . $owner . '_' . $client_name;
        $mysql_user = $owner . '_' . $client_name;

        try {
            $pdo->exec("CREATE DATABASE `$db_name`");
            $pdo->exec("CREATE USER '$mysql_user'@'localhost' IDENTIFIED BY '$client_pass'");
            $pdo->exec("GRANT ALL PRIVILEGES ON `$db_name`.* TO '$mysql_user'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
            $msg = "✅ Đã tạo user `$mysql_user` và database `$db_name`";
        } catch (PDOException $e) {
            $msg = "❌ Lỗi tạo user/db: " . $e->getMessage();
        }
    }
}

// ====== Lấy danh sách database của user ======
function list_databases(PDO $pdo, string $owner): array {
    $dbs = [];
    $stmt = $pdo->query("SHOW DATABASES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        if (strpos($row[0], "db_{$owner}_") === 0) {
            $dbs[] = $row[0];
        }
    }
    return $dbs;
}

// ====== Lấy danh sách user MySQL của user ======
function list_users(PDO $pdo, string $owner): array {
    $users = [];
    $stmt = $pdo->query("SELECT User FROM mysql.user WHERE User LIKE " . $pdo->quote($owner . "_%"));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $users[] = $row['User'];
    }
    return $users;
}

$databases = list_databases($pdo, $owner);
$users = list_users($pdo, $owner);
?>

<section>
  <h1>📂 Quản lý Database của bạn</h1>
  <?php if ($msg) echo "<div class='msg'>$msg</div>"; ?>

  <div class="grid">
    <div class="card">
      <h3>➕ Tạo User + Database</h3>
      <form method="post">
        <input type="text" name="client_name" placeholder="Tên dự án / web" required>
        <input type="password" name="client_pass" placeholder="Mật khẩu" required>
        <button type="submit" name="create_client">Tạo</button>
      </form>
    </div>

    <div class="card">
      <h3>📋 Danh sách Database & User</h3>
      <table>
        <tr><th>Database</th><th>User</th><th>PhpMyAdmin</th><th>Hành động</th></tr>
        <?php foreach ($databases as $db): 
            $user = str_replace('db_','',$db); // ví dụ: admin_blog
        ?>
        <tr>
          <td><?= htmlspecialchars($db) ?></td>
          <td><?= htmlspecialchars($user) ?></td>
          <td>
            <a class="btn" href="/phpmyadmin/" target="_blank" onclick="alert('User: <?= $user ?>\nPass: (mật khẩu khi tạo)')">Mở PhpMyAdmin</a>
          </td>
          <td>
            <a class="btn delete" href="?del_db=<?= urlencode($db) ?>" onclick="return confirm('Xóa database <?= $db ?> và user <?= $user ?>?')">Xóa</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="card">
      <h3>📋 Danh sách User MySQL</h3>
      <table>
        <tr><th>User</th></tr>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</section>

<style>
.grid { display:grid; gap:15px; grid-template-columns: repeat(auto-fit, minmax(280px,1fr)); }
.card { padding:15px; border:1px solid #ddd; border-radius:8px; background:#111B2D; }
.msg { padding:10px; margin-bottom:10px; border-radius:6px; background:#e9ecef; color:#000; }
.btn { padding:5px 12px; border-radius:6px; background:#007bff; color:#fff; text-decoration:none; display:inline-block; }
.btn.delete { background:red; color:#fff; }
table { width:100%; border-collapse:collapse; }
th, td { border:1px solid #ddd; padding:6px; text-align:left; }
@media(max-width:600px){.grid{grid-template-columns:1fr;}}
</style>

<?php include __DIR__ . '/footer.php'; ?>