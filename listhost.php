<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

// Kết nối database
require_once __DIR__ . '/config.php';

$msg = '';

// Xử lý cập nhật host
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Xóa host
    if (isset($_POST['delete_id'])) {
        $id = intval($_POST['delete_id']);

        // Lấy domain để xóa thư mục
        $stmt = $pdo->prepare("SELECT domain FROM hosts WHERE id=?");
        $stmt->execute([$id]);
        $domain = $stmt->fetchColumn();

        if ($domain) {
            // Xóa thư mục host
            $dir = __DIR__ . "/hosts/" . $domain;
            if (is_dir($dir)) {
                $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
                }
                rmdir($dir);
            }

            // Xóa file VirtualHost
            $vhostFile = "/var/www/html/conf.d/vhost_" . $domain . ".conf";
            if (file_exists($vhostFile)) {
                unlink($vhostFile);
            }

            // 🆕 XÓA FILE SSL CONFIG
            $vhostSSLFile = "/var/www/html/conf.d/vhost_" . $domain . "-le-ssl.conf";
            if (file_exists($vhostSSLFile)) {
                unlink($vhostSSLFile);
            }

            // Xóa DB record
            $stmt = $pdo->prepare("DELETE FROM hosts WHERE id=?");
            $stmt->execute([$id]);

            $msg = "Đã xóa host $domain và config SSL!";
        }
    }
    // Cập nhật host
    elseif (isset($_POST['edit_id'])) {
        $id = intval($_POST['edit_id']);
        $stmt = $pdo->prepare("UPDATE hosts SET password=?, disk_quota=?, bandwidth=?, database_count=?, addon_count=?, parked_count=?, email_count=? WHERE id=?");
        $stmt->execute([
            $_POST['password'],
            $_POST['disk_quota'],
            $_POST['bandwidth'],
            $_POST['database'],
            $_POST['addon'],
            $_POST['parked'],
            $_POST['email'],
            $id
        ]);
        $msg = "Cập nhật host thành công!";
    }
    // Thêm SSL cho domain - CÁCH ĐÃ THÀNH CÔNG VỚI t.occ.asia
    elseif (isset($_POST['add_ssl'])) {
        $domain = $_POST['domain'];
        
        // 🆕 CHẠY LỆNH CERTBOT ĐÃ THÀNH CÔNG
        $output = shell_exec("sudo certbot --apache -d occ.asia -d www.occ.asia -d " . escapeshellarg($domain) . " --email duccanhcfd@gmail.com --agree-tos --expand 2>&1");
        
        // Kiểm tra kết quả
        if (strpos($output, 'Congratulations') !== false || strpos($output, 'Successfully') !== false || strpos($output, 'existing') !== false) {
            $msg = "✅ Đã thêm SSL thành công cho <strong>$domain</strong>!<br>
                   🔹 Domain đã được thêm vào certificate chung với occ.asia<br>
                   🔹 HTTPS: <a href='https://$domain' target='_blank'>https://$domain</a>";
        } else {
            $msg = "❌ Lỗi khi thêm SSL cho $domain:<br><small>" . nl2br(htmlspecialchars($output)) . "</small>";
        }
    }
}

// Lấy danh sách host
$hosts = $pdo->query("SELECT * FROM hosts ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<section>
<h1>Danh sách Hosting</h1>
<?php if($msg) echo "<div class='alert alert-info'>".$msg."</div>"; ?>

<style>
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
th, td { border: 1px solid #000; padding: 5px; }
th.domain, td.domain { width: 200px; text-align: left; }
th.password, td.password { width: 150px; text-align: left; }
th.short, td.short { width: 40px; text-align: center; }
input[type="number"], input[type="text"] { text-align: center; width: 100%; box-sizing: border-box; }
input.password-input { width: 120px; }
button { padding: 4px 8px; margin: 2px; }
form.inline { display: inline; }
.btn-ssl { background: #28a745; color: white; border: none; padding: 4px 8px; margin: 2px; cursor: pointer; }
.btn-ssl:hover { background: #218838; }
.ssl-status { font-weight: bold; }
.ssl-active { color: #28a745; }
.ssl-inactive { color: #dc3545; }
</style>

<table>
<tr>
    <th class="domain">Domain</th>
    <th>Username</th>
    <th class="password">Password</th>
    <th class="short">IP Temp</th>
    <th class="short">Disk</th>
    <th class="short">Bandwidth</th>
    <th class="short">DB</th>
    <th class="short">Addon</th>
    <th class="short">Parked</th>
    <th class="short">Email</th>
    <th class="short">SSL Status</th>
    <th>Action</th>
</tr>

<?php foreach($hosts as $h): ?>
<tr>
<form method="post" class="inline">
    <td class="domain">
        <a href="/filemanager.php?dir=hosts/<?php echo urlencode($h['domain']); ?>" target="_blank">
            <?php echo htmlspecialchars($h['domain']); ?>
        </a>
        <input type="hidden" name="edit_id" value="<?php echo $h['id']; ?>">
    </td>

    <td>
        <a href="http://<?php echo $_SERVER['SERVER_ADDR'] . '/hosts/' . urlencode($h['domain']); ?>" target="_blank">
            Link tạm
        </a>
    </td>

    <td><?php echo htmlspecialchars($h['username']); ?></td>
    <td class="password"><input type="text" class="password-input" name="password" value="<?php echo htmlspecialchars($h['password']); ?>"></td>
    <td class="short"><?php echo htmlspecialchars($h['ip_temp']); ?></td>
    <td class="short"><input type="number" name="disk_quota" value="<?php echo $h['disk_quota']; ?>"></td>
    <td class="short"><input type="number" name="bandwidth" value="<?php echo $h['bandwidth']; ?>"></td>
    <td class="short"><input type="number" name="database" value="<?php echo $h['database_count']; ?>"></td>
    <td class="short"><input type="number" name="addon" value="<?php echo $h['addon_count']; ?>"></td>
    <td class="short"><input type="number" name="parked" value="<?php echo $h['parked_count']; ?>"></td>
    <td class="short"><input type="number" name="email" value="<?php echo $h['email_count']; ?>"></td>
    
    <!-- 🆕 CỘT KIỂM TRA SSL THÔNG MINH -->
    <td class='short ssl-status <?php
        $ssl_check = shell_exec("timeout 5 curl -I https://" . escapeshellarg($h['domain']) . " 2>/dev/null | head -1");
        if (strpos($ssl_check, '200') !== false || strpos($ssl_check, 'HTTP/2') !== false) {
            echo 'ssl-active">✅ SSL';
        } else {
            echo 'ssl-inactive">❌ No SSL';
        }
    ?></td>
    
    <td>
        <button type="submit">Lưu</button>
</form>
<form method="post" class="inline" onsubmit="return confirm('Xóa host <?php echo htmlspecialchars($h['domain']); ?>?');">
    <input type="hidden" name="delete_id" value="<?php echo $h['id']; ?>">
    <button type="submit" style="background:red;color:white;">Xóa</button>
</form>
<!-- Nút Add SSL - CHỈ HIỆN KHI CHƯA CÓ SSL -->
<?php
$ssl_check = shell_exec("timeout 5 curl -I https://" . escapeshellarg($h['domain']) . " 2>/dev/null | head -1");
if (strpos($ssl_check, '200') === false && strpos($ssl_check, 'HTTP/2') === false): ?>
<form method="post" class="inline" onsubmit="return confirm('Thêm SSL cho <?php echo htmlspecialchars($h['domain']); ?>?\\n\\nDomain sẽ được thêm vào certificate chung với occ.asia.');">
    <input type="hidden" name="add_ssl" value="1">
    <input type="hidden" name="domain" value="<?php echo htmlspecialchars($h['domain']); ?>">
    <button type="submit" class="btn-ssl" title="Thêm SSL cho <?php echo htmlspecialchars($h['domain']); ?>">Add SSL</button>
</form>
<?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</table>

<div class="mt-3 alert alert-success">
    <strong>🚀 Hệ thống SSL hoàn chỉnh:</strong>
    <ul class="mb-0">
        <li>✅ <strong>Nút "Add SSL":</strong> Thêm domain vào certificate chung với occ.asia</li>
        <li>✅ <strong>Tự động kiểm tra:</strong> Hiển thị trạng thái SSL real-time</li>
        <li>✅ <strong>Thông minh:</strong> Chỉ hiện nút "Add SSL" khi domain chưa có SSL</li>
        <li>✅ <strong>Đã tested:</strong> Hoạt động tốt với t.occ.asia và các domain khác</li>
    </ul>
</div>

<!-- 🆕 QUẢN LÝ SSL TOÀN HỆ THỐNG -->
<div class="text-center mt-4">
    <div class="card">
        <div class="card-body">
            <h5>🔐 Quản lý SSL toàn hệ thống</h5>
            <div class="btn-group">
                <a href="ssl_manager.php" class="btn btn-info mx-2">
                    ⚙️ SSL Manager
                </a>
                <a href="create_host.php" class="btn btn-success mx-2">
                    🚀 Tạo Host Mới
                </a>
            </div>
            <small class="text-muted d-block mt-2">
                SSL Manager giúp bật/tắt SSL toàn hệ thống khi cần bảo trì
            </small>
        </div>
    </div>
</div>
</section>

<?php include __DIR__ . '/footer.php'; ?>