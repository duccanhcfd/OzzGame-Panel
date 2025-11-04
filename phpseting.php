<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

// ================= CONFIG ==================
$msg = '';
$hostsRoot = __DIR__ . "/hosts";

// Lấy danh sách host từ thư mục hosts
$hosts = array_filter(scandir($hostsRoot), function($d) use ($hostsRoot) {
    return $d !== '.' && $d !== '..' && is_dir("$hostsRoot/$d");
});

// Nếu submit form
if (isset($_POST['save_php'])) {
    $host = preg_replace('/[^a-zA-Z0-9_\-]/','',$_POST['host']);
    if (!in_array($host, $hosts)) {
        $msg = "❌ Host không tồn tại!";
    } else {
        $ini_path = "$hostsRoot/$host/.user.ini";
        $settings = [
            'display_errors'    => $_POST['display_errors'] ?? 'Off',
            'upload_max_filesize'=> $_POST['upload_max_filesize'] ?? '10M',
            'post_max_size'      => $_POST['post_max_size'] ?? '12M'
        ];
        file_put_contents($ini_path, implode("\n", array_map(
            fn($k,$v)=>"$k = $v", array_keys($settings), $settings
        )));
        $msg = "✅ Cập nhật PHP settings cho host $host thành công!";
    }
}

// Nếu chọn host, đọc .user.ini hiện tại
$currentSettings = [
    'display_errors'=>'Off',
    'upload_max_filesize'=>'10M',
    'post_max_size'=>'12M'
];
$selectedHost = $_POST['host'] ?? '';
if ($selectedHost && in_array($selectedHost, $hosts)) {
    $ini_path = "$hostsRoot/$selectedHost/.user.ini";
    if (file_exists($ini_path)) {
        foreach (parse_ini_file($ini_path) as $k=>$v) {
            $currentSettings[$k]=$v;
        }
    }
}
?>

<section>
  <h1>⚙️ PHP Settings per Host</h1>
  <?php if ($msg) echo "<div class='msg'>$msg</div>"; ?>

  <form method="post" style="max-width:400px;">
    <label>Chọn host:</label>
    <select name="host" onchange="this.form.submit()">
      <option value="">-- Chọn host --</option>
      <?php foreach ($hosts as $h): ?>
        <option value="<?= htmlspecialchars($h) ?>" <?= ($h==$selectedHost)?'selected':'' ?>><?= htmlspecialchars($h) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($selectedHost): ?>
    <form method="post">
      <input type="hidden" name="host" value="<?= htmlspecialchars($selectedHost) ?>">
      <label>Display Errors:</label>
      <select name="display_errors">
        <option value="On" <?= $currentSettings['display_errors']=='On'?'selected':'' ?>>On</option>
        <option value="Off" <?= $currentSettings['display_errors']=='Off'?'selected':'' ?>>Off</option>
      </select><br><br>

      <label>Upload Max Filesize:</label>
      <input type="text" name="upload_max_filesize" value="<?= htmlspecialchars($currentSettings['upload_max_filesize']) ?>"><br><br>

      <label>Post Max Size:</label>
      <input type="text" name="post_max_size" value="<?= htmlspecialchars($currentSettings['post_max_size']) ?>"><br><br>

      <button type="submit" name="save_php">💾 Lưu Settings</button>
    </form>
  <?php endif; ?>
</section>

<style>
section { padding:15px; max-width:600px; margin:auto; }
.msg { padding:10px; margin-bottom:10px; border-radius:6px; background:#e9ecef; }
form label { display:block; margin:6px 0 2px; font-weight:bold; }
form input, form select { width:100%; padding:6px; margin-bottom:10px; border-radius:6px; border:1px solid #ccc; }
form button { padding:6px 12px; border-radius:6px; background:#007bff; color:#fff; border:none; cursor:pointer; }
form button:hover { background:#0056b3; }
</style>

<?php include __DIR__ . '/footer.php'; ?>