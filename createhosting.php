<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

// ================== CONFIG ==================
$nameServer   = "server.comz.us";
require_once __DIR__ . '/config.php';

$vhostDir     = "/var/www/html/conf.d";
$hostsRoot    = "/var/www/html/panel/hosts";
$templateDir  = __DIR__ . "/templatehost";
$httpdConf    = "/etc/httpd/conf/httpd.conf";
// ============================================

$msg = '';

// 🔹 TỰ ĐỘNG THIẾT LẬP QUYỀN
function setupPermissions() {
    global $vhostDir, $hostsRoot, $templateDir;
    foreach ([$vhostDir, $hostsRoot, $templateDir] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        exec("chmod -R 775 " . escapeshellarg($dir));
    }
}

// 🔹 TỰ ĐỘNG THÊM INCLUDEOPTIONAL VÀO APACHE
function ensureApacheInclude($httpdConf, $vhostDir) {
    $includeLine = "IncludeOptional $vhostDir/*.conf";
    $contents = file_get_contents($httpdConf);
    if (strpos($contents, $includeLine) === false) {
        file_put_contents($httpdConf, PHP_EOL . $includeLine, FILE_APPEND);
    }
}

// Gọi hàm thiết lập
setupPermissions();
ensureApacheInclude($httpdConf, $vhostDir);

// 🔹 Tạo template host
if (!is_file($templateDir . "/index.html")) {
    if (!is_dir($templateDir)) mkdir($templateDir, 0775, true);
    file_put_contents($templateDir . "/index.html",
        '<!DOCTYPE html>
        <html>
        <head>
            <title>Welcome to ##DOMAIN##</title>
            <style>body{font-family:Arial,sans-serif;text-align:center;margin-top:50px}</style>
        </head>
        <body>
            <h1>Welcome to ##DOMAIN##</h1>
            <p>Your hosting account has been created successfully!</p>
            <p>🚀 Website is ready for development</p>
        </body>
        </html>'
    );
}

// 🔹 Xử lý form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $disk_quota = intval($_POST['disk_quota'] ?? 1024);
    $bandwidth  = intval($_POST['bandwidth'] ?? 10240);
    $database_count = intval($_POST['database'] ?? 1);
    $addon_count    = intval($_POST['addon'] ?? 0);
    $parked_count   = intval($_POST['parked'] ?? 0);
    $email_count    = intval($_POST['email'] ?? 1);

    if (!preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
        $msg = "❌ Tên miền không hợp lệ!";
    } else {
        $username = substr(preg_replace('/[^a-z0-9]/i', '', $domain), 0, 6);
        if (!$password) $password = bin2hex(random_bytes(4));
        $ip_temp = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';

        // Kiểm tra domain tồn tại trong DB
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM hosts WHERE domain=?");
        $stmt->execute([$domain]);
        if ($stmt->fetchColumn() > 0) {
            $msg = "⚠️ Domain $domain đã tồn tại!";
        } else {
            try {
                $pdo->beginTransaction();

                // Lưu vào DB
                $stmt = $pdo->prepare("INSERT INTO hosts
                    (domain, username, password, ip_temp, disk_quota, bandwidth, database_count, addon_count, parked_count, email_count)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$domain, $username, $password, $ip_temp, $disk_quota, $bandwidth, $database_count, $addon_count, $parked_count, $email_count]);

                // Tạo thư mục host
                $dst = $hostsRoot . "/" . $domain;
                if (!is_dir($dst)) mkdir($dst, 0775, true);

                // Copy template
                copy($templateDir . "/index.html", "$dst/index.html");
                $indexContent = file_get_contents("$dst/index.html");
                $indexContent = str_replace('##DOMAIN##', $domain, $indexContent);
                file_put_contents("$dst/index.html", $indexContent);

                // Set quyền
                exec("chmod -R 775 " . escapeshellarg($dst));

                // Tạo symlink
                $symlinkPath = "/var/www/html/$domain";
                if (!file_exists($symlinkPath)) symlink($dst, $symlinkPath);

                // 🔹 TẠO HTTP CONFIG
                $confFile = $vhostDir . "/vhost_$domain.conf";
                
                // Kiểm tra không trùng file panel
                $protectedConfigs = ['panel.conf', 'phpmyadmin.conf', 'ssl.conf', 'welcome.conf'];
                if (in_array(basename($confFile), $protectedConfigs)) {
                    throw new Exception("Tên file trùng với hệ thống panel!");
                }
                if (file_exists($confFile)) throw new Exception("File cấu hình đã tồn tại: $confFile");

                $vhostConfig = "<VirtualHost *:80>
    ServerName $domain
    ServerAlias www.$domain
    DocumentRoot \"$dst\"

    <Directory \"$dst\">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /var/log/httpd/{$domain}-error.log
    CustomLog /var/log/httpd/{$domain}-access.log combined
</VirtualHost>";

                file_put_contents($confFile, $vhostConfig);
                exec("chmod 644 " . escapeshellarg($confFile));

                // 🆕 TEST APACHE VÀ BỎ QUA LỖI SSL
                $sslWarning = "";
                exec("/usr/sbin/apachectl configtest 2>&1", $outTest, $retTest);

                // Nếu là lỗi SSL, vẫn cho phép tạo host
                $errorMsg = implode(" ", $outTest);
                if ($retTest !== 0) {
                    if (strpos($errorMsg, 'SSLCertificateFile') !== false && 
                        strpos($errorMsg, 'does not exist or is empty') !== false) {
                        // Lỗi SSL - vẫn cho phép tiếp tục
                        $sslWarning = "⚠️ Có lỗi SSL config nhưng host vẫn được tạo";
                    } else {
                        // Lỗi khác - dừng lại
                        throw new Exception("Lỗi cấu hình Apache: " . $errorMsg);
                    }
                }

                // Reload Apache
                exec("sudo systemctl reload httpd", $outReload, $retReload);
                $reloadMessage = ($retReload === 0) ? "✅ Apache đã reload tự động" : "⚠️ Không thể reload Apache tự động, hãy reload thủ công.";

                $pdo->commit();

                $msg = "✅ Host <strong>$domain</strong> đã được tạo thành công!<br>
                        🔹 Truy cập tạm: <a href='http://$nameServer/hosts/$domain/' target='_blank'>http://$nameServer/hosts/$domain/</a><br>
                        🔹 Domain thật: <a href='http://$domain' target='_blank'>http://$domain</a><br>
                        🔹 Username: <strong>$username</strong><br>
                        🔹 Password: <strong>$password</strong><br>
                        🔹 Thư mục: <strong>$dst</strong><br>
                        🔹 File config: <strong>$confFile</strong><br>" .
                        ($sslWarning ? "<br>🔸 $sslWarning" : "") . "<br>
                        $reloadMessage";

            } catch (Exception $e) {
                $pdo->rollBack();
                if (isset($confFile) && file_exists($confFile)) unlink($confFile);
                if (isset($dst) && is_dir($dst)) exec("rm -rf " . escapeshellarg($dst));
                $msg = "❌ Lỗi: " . $e->getMessage();
            }
        }
    }
}
?>

<section>
<h1>🎯 Tạo Hosting</h1>
<?php if($msg): ?>
<div style="border:1px solid #4CAF50; padding:10px; margin-bottom:15px; background:#f0fff0; color:#000000; border-radius:6px;">
    <?= $msg ?>
</div>
<?php endif; ?>

<form method="post" style="max-width:600px; border:1px solid #ddd; padding:20px; border-radius:6px; background:#7D7D7D;">
    <label>Tên miền:</label>
    <input type="text" name="domain" required style="width:100%; padding:8px; margin-bottom:10px;">
    <label>Password (để trống để tự sinh):</label>
    <input type="text" name="password" style="width:100%; padding:8px; margin-bottom:10px;">

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
        <div>
            <label>Disk Quota (MB):</label>
            <input type="number" name="disk_quota" value="1024" style="width:100%;">
        </div>
        <div>
            <label>Bandwidth (MB):</label>
            <input type="number" name="bandwidth" value="10240" style="width:100%;">
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:10px; margin-bottom:10px;">
        <div>
            <label>Database:</label>
            <input type="number" name="database" value="1" min="0" style="width:100%;">
        </div>
        <div>
            <label>Addon Domains:</label>
            <input type="number" name="addon" value="0" min="0" style="width:100%;">
        </div>
        <div>
            <label>Parked Domains:</label>
            <input type="number" name="parked" value="0" min="0" style="width:100%;">
        </div>
        <div>
            <label>Email Accounts:</label>
            <input type="number" name="email" value="1" min="0" style="width:100%;">
        </div>
    </div>

    <button type="submit" style="padding:12px 25px; border:none; background:#4CAF50; color:#fff; border-radius:4px; cursor:pointer; font-size:16px;">
        🚀 Tạo Hosting
    </button>
</form>

<div style="margin-top:20px; padding:15px; background:#7d7d7d; border-radius:6px; border:1px solid #b8daff;">
    <h3>📋 Thông tin hệ thống:</h3>
    <p>✅ <strong>Bỏ qua lỗi SSL:</strong> Host vẫn được tạo ngay cả khi có lỗi SSL config</p>
    <p>✅ <strong>Thời đại 4.0:</strong> Tự động xử lý lỗi và tiếp tục hoạt động</p>
    <p>⚠️ <strong>Lưu ý:</strong> Lỗi SSL không ảnh hưởng đến việc tạo host mới</p>
</div>
</section>

<?php include __DIR__ . '/footer.php'; ?>