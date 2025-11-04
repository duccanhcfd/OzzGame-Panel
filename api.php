<?php
// panel/api.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

// 🔹 Cấu hình DB
require_once __DIR__ . '/config.php'; // $pdo = db_real_connect();

// Nhận dữ liệu POST
$token       = $_POST['token'] ?? '';
$action      = $_POST['action'] ?? '';
$domain      = strtolower(trim($_POST['domain'] ?? ''));
$password    = trim($_POST['password'] ?? '');
$disk_quota  = intval($_POST['disk_quota'] ?? 1024);
$bandwidth   = intval($_POST['bandwidth'] ?? 10240);
$database    = intval($_POST['database'] ?? 1);
$addon       = intval($_POST['addon'] ?? 0);
$parked      = intval($_POST['parked'] ?? 0);
$email       = intval($_POST['email'] ?? 1);
$ip_temp     = $_POST['ip_temp'] ?? ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');

$hostsRoot   = "/var/www/html/panel/hosts";
$vhostDir    = "/var/www/html/conf.d";
$templateDir = __DIR__ . "/templatehost";

try {
    // 🔹 Kiểm tra API token
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM api_keys WHERE token=? AND is_active=1");
    $stmt->execute([$token]);
    if ($stmt->fetchColumn() == 0) {
        echo json_encode(['status'=>'error','msg'=>'Invalid API key']);
        exit;
    }

    // 🔹 Kiểm tra domain hợp lệ
    if (!$domain || !preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
        echo json_encode(['status'=>'error','msg'=>'Domain trống hoặc không hợp lệ']);
        exit;
    }

    switch($action) {
        case 'create':
            // Domain đã tồn tại?
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM hosts WHERE domain=?");
            $stmt->execute([$domain]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['status'=>'error','msg'=>"Domain $domain đã tồn tại"]);
                exit;
            }

            $username = substr(preg_replace('/[^a-z0-9]/i','',$domain),0,6);
            if (!$password) $password = bin2hex(random_bytes(4));
            $dst = "$hostsRoot/$domain";
            if (!is_dir($dst)) mkdir($dst, 0775, true);

            // Template index
            if (!is_file("$templateDir/index.html")) {
                if (!is_dir($templateDir)) mkdir($templateDir, 0775, true);
                file_put_contents("$templateDir/index.html",
                    '<!DOCTYPE html><html><head><title>Welcome to ##DOMAIN##</title></head><body><h1>Welcome to ##DOMAIN##</h1></body></html>'
                );
            }
            copy("$templateDir/index.html", "$dst/index.html");
            $indexContent = str_replace('##DOMAIN##', $domain, file_get_contents("$dst/index.html"));
            file_put_contents("$dst/index.html", $indexContent);
            exec("chmod -R 775 " . escapeshellarg($dst));

            // Lưu DB
            $stmt = $pdo->prepare("INSERT INTO hosts
                (domain, username, password, ip_temp, disk_quota, bandwidth, database_count, addon_count, parked_count, email_count)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$domain, $username, $password, $ip_temp, $disk_quota, $bandwidth, $database, $addon, $parked, $email]);

            // VirtualHost cho domain
            $confFile = "$vhostDir/vhost_$domain.conf";
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

            // VirtualHost tạm cho IP/domain
            $confFileIp = "$vhostDir/vhost_{$domain}_ip.conf";
            $vhostConfigIp = "
<VirtualHost *:80>
ServerName $ip_temp
DocumentRoot \"$hostsRoot\"

Alias /$domain \"$dst/\"
<Directory \"$dst\">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

ErrorLog /var/log/httpd/{$domain}_ip-error.log
CustomLog /var/log/httpd/{$domain}_ip-access.log combined
</VirtualHost>";
            file_put_contents($confFileIp, $vhostConfigIp);
            exec("chmod 644 " . escapeshellarg($confFileIp));

            exec("sudo systemctl reload httpd");

            echo json_encode([
                'status'=>'success',
                'msg'=>'Host created successfully',
                'domain'=>$domain,
                'username'=>$username,
                'password'=>$password,
                'path'=>$dst,
                'conf'=>$confFile,
                'temp_url'=>"http://$ip_temp/$domain/"
            ]);
            break;

        case "changepass":
            $domain   = $_POST['domain'] ?? '';
            $password = $_POST['password'] ?? '';

            if (!$domain || !$password) {
                echo json_encode(['status'=>'error','msg'=>'Missing params']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE hosts SET password=? WHERE domain=?");
            $stmt->execute([$password, $domain]);

            echo json_encode([
                'status'  => 'success',
                'msg'     => "Password updated",
                'domain'  => $domain,
                'password'=> $password
            ]);
            break;

        case "suspend":
            $dst = "$hostsRoot/$domain";
            if (!is_dir($dst)) {
                echo json_encode(['status'=>'error','msg'=>"Domain $domain không tồn tại"]);
                exit;
            }
            exec("chmod -R 000 " . escapeshellarg($dst));
            exec("sudo systemctl reload httpd");
            echo json_encode(['status'=>'success','msg'=>"Host $domain suspended"]);
            break;

        case "restore":
            $dst = "$hostsRoot/$domain";
            if (!is_dir($dst)) {
                echo json_encode(['status'=>'error','msg'=>"Domain $domain không tồn tại"]);
                exit;
            }
            exec("chmod -R 775 " . escapeshellarg($dst));
            exec("sudo systemctl reload httpd");
            echo json_encode(['status'=>'success','msg'=>"Host $domain restored"]);
            break;

        case "status":
            $domain = $_POST['domain'];
            echo json_encode(['status'=>'success','domain'=>$domain,'state'=>'active']);
            break;

        case 'delete':
            $stmt = $pdo->prepare("SELECT id FROM hosts WHERE domain=?");
            $stmt->execute([$domain]);
            if (!$stmt->fetchColumn()) {
                echo json_encode(['status'=>'error','msg'=>"Domain $domain không tồn tại"]);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM hosts WHERE domain=?");
            $stmt->execute([$domain]);

            $confFile = "$vhostDir/vhost_$domain.conf";
            if (file_exists($confFile)) unlink($confFile);

            $confFileIp = "$vhostDir/vhost_{$domain}_ip.conf";
            if (file_exists($confFileIp)) unlink($confFileIp);

            $dst = "$hostsRoot/$domain";
            if (is_dir($dst)) exec("rm -rf " . escapeshellarg($dst));
            exec("sudo systemctl reload httpd");

            echo json_encode(['status'=>'success','msg'=>"Host $domain deleted"]);
            break;

        default:
            echo json_encode(['status'=>'error','msg'=>'Unknown action']);
            exit;
    }

} catch (Exception $e) {
    echo json_encode(['status'=>'error','msg'=>$e->getMessage()]);
}