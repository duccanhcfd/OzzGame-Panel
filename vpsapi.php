<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

// Lấy header Authorization
$headers = getallheaders();
$bearer = $headers['Authorization'] ?? '';
$token = str_replace('Bearer ','',$bearer);

// Kiểm tra API key trong SQL
$stmt = $pdo->prepare("SELECT * FROM vpsapi_key WHERE api_key=? AND status='active'");
$stmt->execute([$token]);
$api = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$api){
    http_response_code(401);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

// Hàm kiểm tra Docker
function checkDocker(){
    exec("docker info 2>&1", $output, $ret);
    if($ret !== 0){
        http_response_code(500);
        echo json_encode(['error'=>'Docker chưa có quyền truy cập', 'detail'=>$output]);
        exit;
    }
}

// Hàm lấy IP server
function getServerIP(){
    $ip = trim(shell_exec("hostname -I | awk '{print $1}'"));
    return $ip ?: '<IP_host>';
}

// Lấy dữ liệu từ JSON POST
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

checkDocker();

switch($action){

    case 'create':
        $name     = $input['name'] ?? 'vps'.rand(1000,9999);
        $os       = $input['os'] ?? 'Ubuntu';
        $ram      = $input['ram'] ?? '200m';
        $cpu      = $input['cpu'] ?? '0.5';
        $disk     = $input['disk'] ?? '1G';
        $port_ssh = !empty($input['port_ssh']) ? intval($input['port_ssh']) : (2222 + rand(0,50));
        $port_web = !empty($input['port_web']) ? intval($input['port_web']) : 0;

        $ssh_user = 'root';
        $ssh_pass = 'root';
        $image    = 'rastasheep/ubuntu-sshd';

        // Pull image nếu chưa có
        exec("docker image inspect $image > /dev/null 2>&1", $inspect, $ret);
        if($ret !== 0){
            exec("docker pull $image 2>&1", $pull_out, $pull_ret);
            if($pull_ret !== 0){
                http_response_code(500);
                echo json_encode(['error'=>'Lỗi tải image', 'detail'=>$pull_out]);
                exit;
            }
        }

        // Tạo container
        $cmd = "docker run -d --name $name --memory='$ram' --cpus='$cpu' -p $port_ssh:22 $image";
        exec($cmd.' 2>&1', $output, $ret);

        if($ret === 0){
            $server_ip = getServerIP();
            $web_url = $port_web ? "http://$server_ip:$port_web" : '';

            // Lưu vào DB
            $stmt = $pdo->prepare("INSERT INTO vps 
                (name, os, ram, cpu, disk, port_ssh, port_web, ssh_user, ssh_pass, web_url, status)
                VALUES (?,?,?,?,?,?,?,?,?,?, 'running')");
            $stmt->execute([$name,$os,$ram,$cpu,$disk,$port_ssh,$port_web,$ssh_user,$ssh_pass,$web_url]);

            echo json_encode([
                'status'=>'success',
                'vps_id'=>$pdo->lastInsertId(),
                'vps_name'=>$name,
                'ssh_user'=>$ssh_user,
                'ssh_pass'=>$ssh_pass,
                'port_ssh'=>$port_ssh,
                'web_url'=>$web_url
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error'=>'Lỗi tạo container', 'detail'=>$output]);
        }
        break;

    case 'delete':
        $vps_id = intval($input['vps_id'] ?? 0);
        if(!$vps_id){
            echo json_encode(['error'=>'Chưa cung cấp vps_id']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT name FROM vps WHERE id=?");
        $stmt->execute([$vps_id]);
        $vps = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$vps){
            echo json_encode(['error'=>'VPS không tồn tại']);
            exit;
        }
        exec("docker rm -f ".$vps['name'].' 2>&1', $out, $ret);
        $pdo->prepare("DELETE FROM vps WHERE id=?")->execute([$vps_id]);
        echo json_encode(['status'=>'deleted','vps_id'=>$vps_id]);
        break;

    case 'stop':
        $vps_id = intval($input['vps_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name FROM vps WHERE id=?");
        $stmt->execute([$vps_id]);
        $vps = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$vps){
            echo json_encode(['error'=>'VPS không tồn tại']);
            exit;
        }
        exec("docker stop ".$vps['name'].' 2>&1', $out, $ret);
        $pdo->prepare("UPDATE vps SET status='stopped' WHERE id=?")->execute([$vps_id]);
        echo json_encode(['status'=>'stopped','vps_id'=>$vps_id]);
        break;

    case 'start':
        $vps_id = intval($input['vps_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name FROM vps WHERE id=?");
        $stmt->execute([$vps_id]);
        $vps = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$vps){
            echo json_encode(['error'=>'VPS không tồn tại']);
            exit;
        }
        exec("docker start ".$vps['name'].' 2>&1', $out, $ret);
        $pdo->prepare("UPDATE vps SET status='running' WHERE id=?")->execute([$vps_id]);
        echo json_encode(['status'=>'running','vps_id'=>$vps_id]);
        break;

    case 'list':
        $stmt = $pdo->query("SELECT * FROM vps ORDER BY created_at DESC");
        $vps_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['vps'=>$vps_list]);
        break;

    default:
        echo json_encode(['error'=>'Action không hợp lệ']);
}