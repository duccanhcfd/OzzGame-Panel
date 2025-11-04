<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';
require_once __DIR__ . '/config.php'; // Kết nối DB

$user = $_SESSION['username'] ?? '';

// === Xử lý thêm job ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_job'])) {
    $minute  = trim($_POST['minute']);
    $hour    = trim($_POST['hour']);
    $day     = trim($_POST['day']);
    $month   = trim($_POST['month']);
    $weekday = trim($_POST['weekday']);
    $command = trim($_POST['command']);

    $schedule = "$minute $hour $day $month $weekday";

    $stmt = $pdo->prepare("INSERT INTO cronjobs (user, schedule, command) VALUES (?, ?, ?)");
    $stmt->execute([$user, $schedule, $command]);
}

// === Xử lý xoá job ===
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM cronjobs WHERE id=? AND user=?");
    $stmt->execute([$id, $user]);
}

// === Lấy danh sách job ===
$stmt = $pdo->prepare("SELECT * FROM cronjobs WHERE user=? ORDER BY id DESC");
$stmt->execute([$user]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === Xuất crontab cho user ===
if ($jobs) {
    $cronFile = "/tmp/cron_$user.txt";
    $f = fopen($cronFile, "w");
    foreach ($jobs as $job) {
        fwrite($f, $job['schedule'] . " " . $job['command'] . "\n");
    }
    fclose($f);

    // Ghi vào crontab thật
    exec("sudo crontab -u $user $cronFile");
    unlink($cronFile);
}
?>

<div class="container">
    <h2>Cron Jobs cho user: <?= htmlspecialchars($user) ?></h2>

    <h3>Danh sách Cron Jobs</h3>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>Lịch</th>
            <th>Lệnh</th>
            <th>Xoá</th>
        </tr>
        <?php foreach ($jobs as $job): ?>
        <tr>
            <td><?= $job['id'] ?></td>
            <td><?= htmlspecialchars($job['schedule']) ?></td>
            <td><?= htmlspecialchars($job['command']) ?></td>
            <td><a href="?delete=<?= $job['id'] ?>" onclick="return confirm('Xoá job này?')">❌</a></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h3>Thêm Cron Job mới</h3>
    <form method="post">
        <label>Minute:</label> <input type="text" name="minute" value="*">  
        <label>Hour:</label> <input type="text" name="hour" value="*">  
        <label>Day:</label> <input type="text" name="day" value="*">  
        <label>Month:</label> <input type="text" name="month" value="*">  
        <label>Weekday:</label> <input type="text" name="weekday" value="*">  
        <br><br>
        <label>Command:</label> <input type="text" name="command" size="60" placeholder="php /var/www/html/hosts/tenmien/script.php">
        <br><br>
        <button type="submit" name="add_job">Thêm Job</button>
    </form>
</div>