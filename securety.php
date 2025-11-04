<?php
require_once __DIR__ . '/auth.php';
require_login(); // chỉ admin

include __DIR__ . '/header.php'; // header panelcomz

$logFile = __DIR__ . '/panel_security_full.log';

// Hàm chạy lệnh shell
function runCommand($cmd){
    $output = [];
    $status = 0;
    exec($cmd.' 2>&1', $output, $status);
    return ['cmd'=>$cmd,'status'=>$status,'output'=>implode("\n",$output)];
}

// Hàm ghi log
function writeLog($action, $results){
    global $logFile;
    file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] Action: $action\n", FILE_APPEND);
    foreach($results as $r){
        file_put_contents($logFile, $r['cmd']." => ".$r['status']."\n".$r['output']."\n\n", FILE_APPEND);
    }
}

// Xử lý các hành động
$results = [];
if(isset($_POST['action'])){
    switch($_POST['action']){
        // Firewall nâng cao
        case 'firewall_on':
            $cmds = [
                "sudo firewall-cmd --permanent --add-port=2082/tcp",
                "sudo firewall-cmd --permanent --add-port=2083/tcp",
                "sudo firewall-cmd --permanent --add-service=http",
                "sudo firewall-cmd --permanent --add-service=https",
                "sudo firewall-cmd --permanent --add-rich-rule='rule family=ipv4 source address=YOUR_IP accept'",
                "sudo firewall-cmd --reload"
            ];
            foreach($cmds as $cmd){ $results[] = runCommand($cmd); }
            break;
        case 'firewall_off':
            $cmds = [
                "sudo firewall-cmd --permanent --remove-port=2082/tcp",
                "sudo firewall-cmd --permanent --remove-port=2083/tcp",
                "sudo firewall-cmd --permanent --remove-service=http",
                "sudo firewall-cmd --permanent --remove-service=https",
                "sudo firewall-cmd --permanent --remove-rich-rule='rule family=ipv4 source address=YOUR_IP accept'",
                "sudo firewall-cmd --reload"
            ];
            foreach($cmds as $cmd){ $results[] = runCommand($cmd); }
            break;

        // Fail2Ban custom panel
        case 'fail2ban_on':
            // Tạo file jail custom nếu chưa có
            $jailFile = '/etc/fail2ban/jail.d/panelcomz.local';
            if(!file_exists($jailFile)){
                $conf = "[panelcomz]\nenabled = true\nfilter = panelcomz\naction = iptables[name=PanelComz, port=2082, protocol=tcp]\nlogpath = /var/log/panel.log\nmaxretry = 5\nbantime = 3600\n";
                file_put_contents($jailFile, $conf);
            }
            $results[] = runCommand("sudo systemctl enable --now fail2ban");
            break;
        case 'fail2ban_off':
            $results[] = runCommand("sudo systemctl stop fail2ban");
            break;

        // File security
        case 'file_secure':
            $results[] = runCommand("sudo chown -R apache:apache /var/www/html/hosts");
            $results[] = runCommand("sudo find /var/www/html/hosts -type d -exec chmod 755 {} \\;");
            $results[] = runCommand("sudo find /var/www/html/hosts -type f -exec chmod 644 {} \\;");
            break;

        // SSL
        case 'ssl_cert':
            if(!empty($_POST['domain'])){
                $domain = escapeshellarg($_POST['domain']);
                $results[] = runCommand("sudo certbot --apache -d $domain --non-interactive --agree-tos -m admin@$domain");
                $results[] = runCommand("sudo systemctl reload httpd");
            } else { $results[] = ['cmd'=>'SSL','status'=>1,'output'=>'Domain trống']; }
            break;

        // Logs
        case 'view_log':
            if(file_exists($logFile)){
                $results[] = ['cmd'=>'View Log','status'=>0,'output'=>file_get_contents($logFile)];
            } else { $results[] = ['cmd'=>'View Log','status'=>1,'output'=>'Log chưa tồn tại']; }
            break;

        // Status
        case 'check_status':
            $results[] = runCommand("sudo firewall-cmd --list-all");
            $results[] = runCommand("sudo systemctl status fail2ban");
            $results[] = runCommand("uptime");
            $results[] = runCommand("df -h /");
            $results[] = runCommand("free -m");
            break;

        // Backup + Rollback
        case 'backup':
            $results[] = runCommand("sudo cp -r /etc/firewalld /etc/firewalld.bak");
            $results[] = runCommand("sudo cp -r /etc/fail2ban /etc/fail2ban.bak");
            break;
        case 'rollback':
            $results[] = runCommand("sudo cp -r /etc/firewalld.bak/* /etc/firewalld/");
            $results[] = runCommand("sudo cp -r /etc/fail2ban.bak/* /etc/fail2ban/");
            $results[] = runCommand("sudo firewall-cmd --reload");
            $results[] = runCommand("sudo systemctl restart fail2ban");
            break;
    }

    writeLog($_POST['action'], $results);
}

?>

<div class="container">
    <h2 class="panel-title">Security & Firewall Full</h2>

    <form method="post" class="panel-form">
        <div class="panel-section">
            <h3>Firewall</h3>
            <button class="btn btn-primary" name="action" value="firewall_on">Bật Firewall + IP whitelist</button>
            <button class="btn btn-danger" name="action" value="firewall_off">Tắt Firewall</button>
        </div>

        <div class="panel-section">
            <h3>Fail2Ban</h3>
            <button class="btn btn-primary" name="action" value="fail2ban_on">Bật Fail2Ban</button>
            <button class="btn btn-danger" name="action" value="fail2ban_off">Tắt Fail2Ban</button>
        </div>

        <div class="panel-section">
            <h3>File Security</h3>
            <button class="btn btn-secondary" name="action" value="file_secure">Set quyền & audit file</button>
        </div>

        <div class="panel-section">
            <h3>SSL / HTTPS</h3>
            Domain: <input type="text" name="domain" class="input-field" placeholder="example.com">
            <button class="btn btn-primary" name="action" value="ssl_cert">Cài SSL</button>
        </div>

        <div class="panel-section">
            <h3>Backup / Rollback</h3>
            <button class="btn btn-warning" name="action" value="backup">Backup Config</button>
            <button class="btn btn-danger" name="action" value="rollback">Rollback</button>
        </div>

        <div class="panel-section">
            <h3>Logs & Status</h3>
            <button class="btn btn-info" name="action" value="view_log">Xem log</button>
            <button class="btn btn-info" name="action" value="check_status">Kiểm tra status</button>
        </div>
    </form>

    <?php
    if(!empty($results)){
        echo "<div class='panel-results'><h3>Kết quả:</h3><pre>";
        foreach($results as $r){
            echo "Command: ".$r['cmd']."\n";
            echo "Status: ".$r['status']."\n";
            echo "Output:\n".$r['output']."\n";
            echo "-----------------------------\n";
        }
        echo "</pre></div>";
    }
    ?>
</div>

<?php
include __DIR__ . '/footer.php';
?>