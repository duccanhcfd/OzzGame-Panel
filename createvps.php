<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/header.php';

// Hàm kiểm tra Docker
function checkDocker(){
    exec("docker info 2>&1", $output, $ret);
    if($ret !== 0){
        echo "<b>Lỗi:</b> PHP chưa có quyền truy cập Docker.<br>";
        echo "Chi tiết:<pre>".implode("\n",$output)."</pre>";
        exit;
    }
}

// Hàm lấy IP server
function getServerIP(){
    $ip = trim(shell_exec("hostname -I | awk '{print $1}'"));
    return $ip ?: '<IP_host>';
}

// Chỉ chạy khi submit
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    checkDocker();

    $name = $_POST['name'] ?? 'vps'.rand(1000,9999);
    $os   = $_POST['os'] ?? 'Ubuntu';
    $ram  = $_POST['ram'] ?? '200m';
    $cpu  = $_POST['cpu'] ?? '0.5';
    $disk = $_POST['disk'] ?? '1G';

    $port_ssh = !empty($_POST['port_ssh']) ? intval($_POST['port_ssh']) : (2222 + rand(0,50));
    $port_web = !empty($_POST['port_web']) ? intval($_POST['port_web']) : (8080 + rand(0,50));

    $ssh_user = 'root';
    $ssh_pass = 'root'; // mặc định root/root, khách tự đổi sau

    $image = 'rastasheep/ubuntu-sshd';

    // Kiểm tra nếu image chưa có thì pull
    exec("docker image inspect $image > /dev/null 2>&1", $inspect, $ret);
    if($ret !== 0){
        echo "Đang tải image $image ...<br>";
        exec("docker pull $image 2>&1", $pull_out, $pull_ret);
        if($pull_ret !== 0){
            echo "<b>Lỗi tải image!</b><br><pre>".implode("\n",$pull_out)."</pre>";
            exit;
        }
    }

    // Lệnh chạy container mới
    $cmd = "docker run -d --name $name --memory='$ram' --cpus='$cpu' -p $port_ssh:22 -p $port_web:80 $image";
    exec($cmd.' 2>&1', $output, $ret);

    if($ret === 0){
        $server_ip = getServerIP();
        $web_url = "http://$server_ip:$port_web";

        try {
            $stmt = $pdo->prepare("INSERT INTO vps 
                (name, os, ram, cpu, disk, port_ssh, port_web, ssh_user, ssh_pass, web_url, status) 
                VALUES (?,?,?,?,?,?,?,?,?,?, 'running')");
            $stmt->execute([$name,$os,$ram,$cpu,$disk,$port_ssh,$port_web,$ssh_user,$ssh_pass,$web_url]);

            echo "<b>VPS '$name' tạo thành công!</b><br>";
            echo "SSH: $ssh_user / $ssh_pass | Port: $port_ssh<br>";
            echo "Web panel: <a href='$web_url' target='_blank'>$web_url</a><br>";
        } catch (PDOException $e){
            echo "<b>Lỗi ghi DB!</b><br>";
            echo $e->getMessage();
        }
    } else {
        echo "<b>Lỗi tạo container!</b><br>";
        echo "<pre>".implode("\n",$output)."</pre>";
    }
}
?>

<h2>Tạo VPS mới</h2>
<form method="POST">
    Tên VPS: <input type="text" name="name" required><br><br>
    
    OS: 
    <select name="os">
        <option value="Ubuntu">Ubuntu</option>
        <option value="AlmaLinux">AlmaLinux</option>
    </select><br><br>

    RAM:
    <select name="ram">
        <option value="128m">128 MB</option>
        <option value="256m">256 MB</option>
        <option value="512m">512 MB</option>
        <option value="1g">1 GB</option>
        <option value="2g">2 GB</option>
    </select><br><br>

    CPU:
    <select name="cpu">
        <option value="0.1">0.1 Core</option>
        <option value="0.25">0.25 Core</option>
        <option value="0.5">0.5 Core</option>
        <option value="1">1 Core</option>
        <option value="2">2 Core</option>
    </select><br><br>

    HDD:
    <select name="disk">
        <option value="1G">1 GB</option>
        <option value="2G">2 GB</option>
        <option value="5G">5 GB</option>
        <option value="10G">10 GB</option>
    </select><br><br>

    Port SSH: <input type="text" name="port_ssh" placeholder="2222"><br><br>
    Port Web: <input type="text" name="port_web" placeholder="8080"><br><br>

    <button type="submit">Tạo VPS</button>
</form>

<?php
require_once __DIR__ . '/footer.php';
?>