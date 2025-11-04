<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

// --- Thư mục host ---
$hosts_root = realpath(__DIR__ . '/../hosts');
$host = $_GET['host'] ?? '';
$host = preg_replace('/[^a-zA-Z0-9_\-\.]/','',$host);
$root = $host ? realpath($hosts_root.'/'.$host) : realpath(__DIR__);
if (!$root || ($host && strpos($root,$hosts_root)!==0)) $root = realpath(__DIR__);

// --- Thông số PHP cho phép chỉnh ---
$php_settings = [
    'memory_limit',
    'upload_max_filesize',
    'post_max_size',
    'max_execution_time',
    'max_input_time',
    'max_input_vars',
    'default_socket_timeout'
];

// --- File .user.ini ---
$user_ini_file = $root.'/.user.ini';
$messages = [];

// --- Lưu POST ---
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_ini'])){
    $lines = [];
    foreach($php_settings as $key){
        if(!empty($_POST[$key])){
            $lines[] = "$key=".$_POST[$key];
        }
    }
    file_put_contents($user_ini_file, implode("\n",$lines)."\n");
    $messages[] = "Đã lưu .user.ini tại $user_ini_file";
}

// --- Đọc giá trị hiện tại ---
$current = [];
if(file_exists($user_ini_file)){
    $lines = file($user_ini_file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    foreach($lines as $line){
        if(strpos($line,'=')!==false){
            list($k,$v)=explode('=',$line,2);
            $current[$k]=trim($v);
        }
    }
}
foreach($php_settings as $key){
    if(!isset($current[$key])) $current[$key]=ini_get($key);
}
?>

<section>
<h1>Chỉnh PHP INI cho host <?php echo htmlspecialchars($host?:'Panel'); ?></h1>

<?php if($messages): ?>
<div style="padding:5px;background:#eef;margin-bottom:10px;">
<?php foreach($messages as $m) echo htmlspecialchars($m)."<br>"; ?>
</div>
<?php endif; ?>

<form method="post" style="max-width:450px;">
    <?php foreach($php_settings as $key): ?>
    <div style="margin-bottom:8px;">
        <label style="display:inline-block;width:200px;"><?php echo htmlspecialchars($key); ?>:</label>
        <input type="text" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($current[$key]); ?>" style="width:150px;">
    </div>
    <?php endforeach; ?>
    <button type="submit" name="save_ini" style="padding:4px 8px;">Lưu .user.ini</button>
</form>

<p>File .user.ini sẽ áp dụng cho thư mục host này và các subfolder. PHP-FPM có thể mất vài giây để reload giá trị.</p>
</section>

<?php include __DIR__.'/footer.php'; ?>
