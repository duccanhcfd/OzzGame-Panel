<?php
require_once __DIR__ . "/auth.php";
require_login();
require_once __DIR__ . "/config.php";

if (empty($_SESSION['user_id'])) {
    die("Access denied");
}

$msg = "";
$zone_dir = "/etc/pdns/bind/zones";
$zone_file = "$zone_dir/occ.asia.zone";

// Xử lý form submit
if (isset($_POST['save_ns'])) {
    $ns1 = trim($_POST['ns1']);
    $ip1 = trim($_POST['ip1']);
    $ns2 = trim($_POST['ns2']);
    $ip2 = trim($_POST['ip2']);
    $www_ip = trim($_POST['www_ip']);
    $mail_ip = trim($_POST['mail_ip']);

    if (!is_dir($zone_dir)) {
        mkdir($zone_dir, 0755, true);
    }

    $serial = date('Ymd') . '01';

    $content = <<<EOT
\$TTL 3600
@   IN  SOA $ns1. hostmaster.occ.asia. (
        $serial ; Serial
        3600    ; Refresh
        600     ; Retry
        604800  ; Expire
        3600    ; Minimum TTL
)
@       IN  NS      $ns1.
@       IN  NS      $ns2.
$ns1     IN  A       $ip1
$ns2     IN  A       $ip2
www      IN  A       $www_ip
@        IN  MX 10   mail.occ.asia.
mail     IN  A       $mail_ip
EOT;

    file_put_contents($zone_file, $content);

    // Reload PowerDNS
    exec("sudo pdns_control reload 2>&1", $output, $ret);
    if ($ret === 0) {
        $msg = "✅ Zone file đã được tạo và PowerDNS reload thành công!";
    } else {
        $msg = "❌ Lỗi khi reload PowerDNS: " . implode("\n", $output);
    }
}

// Load mặc định
$config = [
    'ns1' => 'ns1.example.com',
    'ip1' => '127.0.0.1',
    'ns2' => 'ns2.example.com',
    'ip2' => '127.0.0.1',
    'www_ip' => '127.0.0.1',
    'mail_ip' => '127.0.0.1'
];
if (file_exists($zone_file)) {
    $lines = file($zone_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/^(ns1)\s+IN\s+A\s+([0-9.]+)/', $line, $m)) $config['ip1'] = $m[2];
        if (preg_match('/^(ns2)\s+IN\s+A\s+([0-9.]+)/', $line, $m)) $config['ip2'] = $m[2];
        if (preg_match('/^(www)\s+IN\s+A\s+([0-9.]+)/', $line, $m)) $config['www_ip'] = $m[2];
        if (preg_match('/^(mail)\s+IN\s+A\s+([0-9.]+)/', $line, $m)) $config['mail_ip'] = $m[2];
        if (preg_match('/^@.*IN\s+NS\s+(\S+)/', $line, $m)) {
            if (!isset($config['ns1'])) $config['ns1'] = $m[1];
            elseif (!isset($config['ns2'])) $config['ns2'] = $m[1];
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Cấu hình Nameserver</title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<?php include __DIR__ . "/header.php"; ?>
<main class="container" style="padding:20px;">
<h2>Cấu hình Nameserver & Zone</h2>
<?php if ($msg) echo "<p style='color:green'>$msg</p>"; ?>
<form method="post">
    NS1: <input type="text" name="ns1" value="<?=htmlspecialchars($config['ns1'])?>"><br>
    IP1: <input type="text" name="ip1" value="<?=htmlspecialchars($config['ip1'])?>"><br>
    NS2: <input type="text" name="ns2" value="<?=htmlspecialchars($config['ns2'])?>"><br>
    IP2: <input type="text" name="ip2" value="<?=htmlspecialchars($config['ip2'])?>"><br>
    WWW A: <input type="text" name="www_ip" value="<?=htmlspecialchars($config['www_ip'])?>"><br>
    Mail A: <input type="text" name="mail_ip" value="<?=htmlspecialchars($config['mail_ip'])?>"><br>
    <button type="submit" name="save_ns">Lưu & Reload PDNS</button>
</form>
</main>
</body>
</html>