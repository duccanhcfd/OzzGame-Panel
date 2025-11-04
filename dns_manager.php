<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';
require_once __DIR__ . '/config.php'; // kết nối database comzpanel

// ===== XÁC ĐỊNH QUYỀN USER =====
$is_admin = !empty($_SESSION['user']);
$is_host = !empty($_SESSION['host_user']);
$current_domain = $is_host ? $_SESSION['host_domain'] : null;

// ===== XỬ LÝ FORM =====

// Thêm domain (chỉ admin)
if (isset($_POST['add_domain']) && $is_admin) {
    $domain = strtolower(trim($_POST['domain']));
    if (!empty($domain)) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO domains (name, type) VALUES (?, 'MASTER')");
        $stmt->execute([$domain]);
        echo "<p style='color:green'>Đã thêm domain $domain thành công!</p>";
    }
}

// Thêm record
if (isset($_POST['add_record'])) {
    $domain = $_POST['domain'];
    
    // Kiểm tra quyền truy cập domain
    if (!$is_admin && $domain !== $current_domain) {
        echo "<p style='color:red'>Bạn không có quyền thêm record cho domain này!</p>";
    } else {
        $name = $_POST['name'];
        $type = strtoupper($_POST['type']);
        $content = $_POST['content'];
        $ttl = intval($_POST['ttl']);

        $stmt = $pdo->prepare("INSERT INTO records (domain_id, name, type, content, ttl, prio)
            VALUES ((SELECT id FROM domains WHERE name=?), ?, ?, ?, ?, NULL)");
        $stmt->execute([$domain, $name, $type, $content, $ttl]);

        echo "<p style='color:green'>Đã thêm record $type cho $name.$domain</p>";
    }
}

// Xóa domain (chỉ admin)
if (isset($_POST['delete_domain']) && $is_admin) {
    $domain = $_POST['domain'];
    $pdo->prepare("DELETE FROM records WHERE domain_id=(SELECT id FROM domains WHERE name=?)")->execute([$domain]);
    $pdo->prepare("DELETE FROM domains WHERE name=?")->execute([$domain]);
    echo "<p style='color:red'>Đã xóa domain $domain</p>";
}

// Xóa record
if (isset($_POST['delete_record'])) {
    $id = intval($_POST['record_id']);
    
    // Kiểm tra quyền: lấy domain của record
    $stmt = $pdo->prepare("SELECT d.name FROM records r JOIN domains d ON r.domain_id = d.id WHERE r.id = ?");
    $stmt->execute([$id]);
    $record_domain = $stmt->fetchColumn();
    
    if (!$is_admin && $record_domain !== $current_domain) {
        echo "<p style='color:red'>Bạn không có quyền xóa record này!</p>";
    } else {
        $pdo->prepare("DELETE FROM records WHERE id=?")->execute([$id]);
        echo "<p style='color:red'>Đã xóa record ID $id</p>";
    }
}

// Sửa record
if (isset($_POST['edit_record'])) {
    $id = intval($_POST['record_id']);
    
    // Kiểm tra quyền: lấy domain của record
    $stmt = $pdo->prepare("SELECT d.name FROM records r JOIN domains d ON r.domain_id = d.id WHERE r.id = ?");
    $stmt->execute([$id]);
    $record_domain = $stmt->fetchColumn();
    
    if (!$is_admin && $record_domain !== $current_domain) {
        echo "<p style='color:red'>Bạn không có quyền sửa record này!</p>";
    } else {
        $name = $_POST['name'];
        $type = strtoupper($_POST['type']);
        $content = $_POST['content'];
        $ttl = intval($_POST['ttl']);

        $stmt = $pdo->prepare("UPDATE records SET name=?, type=?, content=?, ttl=? WHERE id=?");
        $stmt->execute([$name, $type, $content, $ttl, $id]);

        echo "<p style='color:blue'>Đã cập nhật record ID $id</p>";
    }
}

// ===== LẤY DANH SÁCH DOMAIN =====

if ($is_admin) {
    // Admin: lấy tất cả domain
    $domains1 = $pdo->query("SELECT name FROM domains ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    $domains2 = $pdo->query("SELECT domain FROM hosts ORDER BY domain ASC")->fetchAll(PDO::FETCH_COLUMN);
    $all_domains = array_unique(array_merge($domains1, $domains2));
} else {
    // Host user: chỉ lấy domain của mình
    $all_domains = [$current_domain];
}

sort($all_domains);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>DNS Manager - ComZPanel</title>
<style>
body { font-family: Arial, sans-serif; margin:20px; }
fieldset { margin-bottom:20px; }
table { border-collapse: collapse; margin-top:10px; width: 100%; }
table, th, td { border: 1px solid #aaa; padding: 5px; text-align:left; }
form.inline { display:inline; }
</style>
</head>
<body>
<h1>DNS Manager - ComZPanel</h1>
<p>Đang đăng nhập với tư cách: <strong><?php echo $is_admin ? 'Admin' : 'Host User (' . $current_domain . ')'; ?></strong></p>

<!-- Thêm domain (chỉ hiện với admin) -->
<?php if ($is_admin): ?>
<fieldset>
<legend>Thêm Domain</legend>
<form method="post">
    Domain: <input type="text" name="domain" placeholder="example.com" required>
    <button type="submit" name="add_domain">Thêm</button>
</form>
</fieldset>
<?php endif; ?>

<!-- Thêm record -->
<fieldset>
<legend>Thêm Record</legend>
<form method="post">
    Domain:
    <select name="domain" <?php echo !$is_admin ? 'disabled' : ''; ?>>
        <?php foreach($all_domains as $d): ?>
            <option value="<?=htmlspecialchars($d)?>" <?php echo (!$is_admin && $d === $current_domain) ? 'selected' : ''; ?>>
                <?=$d?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if (!$is_admin): ?>
        <input type="hidden" name="domain" value="<?=$current_domain?>">
    <?php endif; ?>
    <br><br>
    Tên (sub): <input type="text" name="name" placeholder="www" required><br><br>
    Loại: 
    <select name="type">
        <option>A</option>
        <option>CNAME</option>
        <option>MX</option>
        <option>TXT</option>
    </select><br><br>
    Giá trị: <input type="text" name="content" placeholder="1.2.3.4 hoặc domain.com" required><br><br>
    TTL: <input type="number" name="ttl" value="3600"><br><br>
    <button type="submit" name="add_record">Thêm Record</button>
</form>
</fieldset>

<!-- Danh sách domain -->
<fieldset>
<legend>Danh sách Domain & Record</legend>
<?php foreach($all_domains as $d): ?>
    <h3><?=$d?></h3>
    
    <?php if ($is_admin): ?>
    <form method="post" class="inline" onsubmit="return confirm('Xóa domain <?=$d?> ?');">
        <input type="hidden" name="domain" value="<?=$d?>">
        <button type="submit" name="delete_domain">Xóa Domain</button>
    </form>
    <?php endif; ?>

    <?php
    $stmt = $pdo->prepare("SELECT r.* FROM records r JOIN domains d2 ON r.domain_id = d2.id WHERE d2.name=? ORDER BY type, name");
    $stmt->execute([$d]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($records):
    ?>
    <table>
        <tr><th>ID</th><th>Name</th><th>Type</th><th>Content</th><th>TTL</th><th>Hành động</th></tr>
        <?php foreach($records as $r): ?>
        <tr>
            <form method="post">
                <td><?=$r['id']?><input type="hidden" name="record_id" value="<?=$r['id']?>"></td>
                <td><input type="text" name="name" value="<?=$r['name']?>"></td>
                <td>
                    <select name="type">
                        <option <?=$r['type']=='A'?'selected':''?>>A</option>
                        <option <?=$r['type']=='CNAME'?'selected':''?>>CNAME</option>
                        <option <?=$r['type']=='MX'?'selected':''?>>MX</option>
                        <option <?=$r['type']=='TXT'?'selected':''?>>TXT</option>
                    </select>
                </td>
                <td><input type="text" name="content" value="<?=$r['content']?>"></td>
                <td><input type="number" name="ttl" value="<?=$r['ttl']?>"></td>
                <td>
                    <button type="submit" name="edit_record">Lưu</button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('Xóa record ID <?=$r['id']?> ?');">
                <input type="hidden" name="record_id" value="<?=$r['id']?>">
                <button type="submit" name="delete_record">X</button>
            </form>
                </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else: ?>
        <p><i>Chưa có record nào.</i></p>
    <?php endif; ?>
<?php endforeach; ?>
</fieldset>

</body>
</html>
<?php include __DIR__ . '/footer.php'; ?>