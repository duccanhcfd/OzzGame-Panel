<?php
// ========================
// PanelComZ Quota Manager
// ========================
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

// ================= CONFIG =================
$hostsRoot = "/var/www/html/panel/hosts";
$globalQuotaMB = 5120; // 5 GB mặc định

// ================= FUNCTIONS =================
function getFolderSizeKB($dir) {
    if (!is_dir($dir)) return 0; // bỏ qua nếu không phải thư mục
    $size = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if ($file->isFile()) $size += $file->getSize();
    }
    return $size / 1024; // KB
}

function getLargestFiles($dir, $limit = 5) {
    if (!is_dir($dir)) return []; // bỏ qua nếu không phải thư mục
    $allFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $allFiles[$file->getPathname()] = $file->getSize();
        }
    }
    arsort($allFiles);
    return array_slice($allFiles, 0, $limit, true);
}

// ================= SCAN HOSTS =================
// chỉ lấy thư mục, bỏ qua file
$hosts = array_filter(scandir($hostsRoot), function($h) use($hostsRoot){
    $path = $hostsRoot . '/' . $h;
    return $h !== '.' && $h !== '..' && is_dir($path);
});

$report = [];

foreach ($hosts as $host) {
    $hostPath = "$hostsRoot/$host";

    // đọc quota riêng nếu có
    $quotaFile = "$hostPath/.quota";
    $quotaMB = $globalQuotaMB;
    if (file_exists($quotaFile)) {
        $data = parse_ini_file($quotaFile);
        if (isset($data['disk_quota'])) $quotaMB = intval($data['disk_quota']);
    }

    $usedKB = getFolderSizeKB($hostPath);
    $usedMB = round($usedKB / 1024, 2);
    $largestFiles = getLargestFiles($hostPath);
    $overQuota = $usedMB > $quotaMB;

    $report[$host] = [
        'path' => $hostPath,
        'usedMB' => $usedMB,
        'limitMB' => $quotaMB,
        'overQuota' => $overQuota,
        'largestFiles' => $largestFiles
    ];
}
?>

<section style="max-width:1200px;margin:20px auto;">
    <h1 style="color:#3b82f6;">📊 Quota Manager - PanelComZ</h1>

    <table class="table table-striped" style="width:100%;border-collapse:collapse;">
        <thead style="background:#1f2937;color:#fff;">
            <tr>
                <th>Host</th>
                <th>Thư mục</th>
                <th>Đã dùng (MB)</th>
                <th>Quota (MB)</th>
                <th>Trạng thái</th>
                <th>File lớn</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($report as $host => $data): ?>
            <tr style="background:<?= $data['overQuota'] ? '#fef2f2' : '#f0fdf4' ?>">
                <td><?= htmlspecialchars($host) ?></td>
                <td><a href="/hosts/<?= urlencode($host) ?>" target="_blank"><?= htmlspecialchars($data['path']) ?></a></td>
                <td><?= $data['usedMB'] ?></td>
                <td><?= $data['limitMB'] ?></td>
                <td><?= $data['overQuota'] ? '❌ Vượt quota' : '✅ OK' ?></td>
                <td>
                    <?php foreach($data['largestFiles'] as $file => $size): ?>
                        <div><?= htmlspecialchars($file) ?> (<?= round($size/1024/1024,2) ?> MB)</div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php include __DIR__ . '/footer.php'; ?>