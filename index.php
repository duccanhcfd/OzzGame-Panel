<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

function read_first_line(string $path): string {
    $h = @fopen($path, 'r');
    if (!$h) return '';
    $line = fgets($h);
    fclose($h);
    return trim((string)$line);
}

function cpu_model(): string {
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if ($cpuinfo === false) return 'N/A';
    if (preg_match('/model name\s*:\s*(.+)/', $cpuinfo, $m)) return trim($m[1]);
    if (preg_match('/Hardware\s*:\s*(.+)/', $cpuinfo, $m)) return trim($m[1]);
    return 'N/A';
}

function cpu_cores(): int {
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if ($cpuinfo === false) return 0;
    preg_match_all('/^processor\s*:\s*\d+/m', $cpuinfo, $m);
    return max(1, count($m[0]));
}

function load_avg(): array {
    $arr = sys_getloadavg();
    return $arr ?: [0,0,0];
}

function mem_info(): array {
    $meminfo = @file('/proc/meminfo');
    $data = [];
    if ($meminfo) {
        foreach ($meminfo as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $data[$m[1]] = (int)$m[2]; // in kB
            }
        }
    }
    $total = ($data['MemTotal'] ?? 0) * 1024;
    $free  = ($data['MemAvailable'] ?? ($data['MemFree'] ?? 0)) * 1024;
    $used  = max(0, $total - $free);
    return ['total' => $total, 'used' => $used, 'free' => $free];
}

function bytes_fmt(float $b): string {
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u)-1) { $b /= 1024; $i++; }
    return sprintf('%.2f %s', $b, $u[$i]);
}

function disk_info(string $path = '/'): array {
    $total = @disk_total_space($path) ?: 0;
    $free  = @disk_free_space($path) ?: 0;
    $used  = max(0, $total - $free);
    return ['path'=>$path, 'total'=>$total, 'used'=>$used, 'free'=>$free];
}

function net_totals(): array {
    $data = @file('/proc/net/dev');
    $rx = 0; $tx = 0;
    if ($data) {
        foreach ($data as $line) {
            if (strpos($line, ':') === false) continue;
            [$iface, $rest] = array_map('trim', explode(':', $line, 2));
            if ($iface === 'lo' || $iface === '') continue; // ignore loopback
            $cols = preg_split('/\s+/', trim($rest));
            // columns: rx_bytes, rx_packets, rx_errs, rx_drop, rx_fifo, rx_frame, rx_compressed, rx_multicast,
            //          tx_bytes, tx_packets, tx_errs, tx_drop, tx_fifo, tx_colls, tx_carrier, tx_compressed
            if (isset($cols[0], $cols[8])) {
                $rx += (float)$cols[0];
                $tx += (float)$cols[8];
            }
        }
    }
    return ['rx'=>$rx, 'tx'=>$tx];
}

$cpu = [
  'model' => cpu_model(),
  'cores' => cpu_cores(),
  'load'  => load_avg(),
];
$mem = mem_info();
$disk = disk_info('/');
$net = net_totals();
?>
<section>
  <h1>Bảng điều khiển</h1>
  <div class="grid stats">
    <div class="card">
      <h3>CPU</h3>
      <div class="kv"><span>Model</span><strong><?php echo htmlspecialchars($cpu['model']); ?></strong></div>
      <div class="kv"><span>Số lõi</span><strong><?php echo (int)$cpu['cores']; ?></strong></div>
      <div class="kv"><span>Tải (1/5/15m)</span><strong><?php echo implode(' / ', array_map(fn($v)=>number_format($v,2), $cpu['load'])); ?></strong></div>
    </div>
    <div class="card">
      <h3>RAM</h3>
      <div class="progress">
        <?php $mem_pct = $mem['total']>0 ? ($mem['used']/$mem['total']*100) : 0; ?>
        <div class="bar" style="width: <?php echo number_format($mem_pct,2); ?>%"></div>
      </div>
      <div class="kv"><span>Đang dùng</span><strong><?php echo bytes_fmt($mem['used']); ?></strong></div>
      <div class="kv"><span>Tổng</span><strong><?php echo bytes_fmt($mem['total']); ?></strong></div>
    </div>
    <div class="card">
      <h3>Lưu trữ (/)</h3>
      <?php $disk_pct = $disk['total']>0 ? ($disk['used']/$disk['total']*100) : 0; ?>
      <div class="progress">
        <div class="bar" style="width: <?php echo number_format($disk_pct,2); ?>%"></div>
      </div>
      <div class="kv"><span>Đang dùng</span><strong><?php echo bytes_fmt($disk['used']); ?></strong></div>
      <div class="kv"><span>Tổng</span><strong><?php echo bytes_fmt($disk['total']); ?></strong></div>
    </div>
    <div class="card">
      <h3>Băng thông (từ khi boot)</h3>
      <div class="kv"><span>Nhận (RX)</span><strong><?php echo bytes_fmt($net['rx']); ?></strong></div>
      <div class="kv"><span>Gửi (TX)</span><strong><?php echo bytes_fmt($net['tx']); ?></strong></div>
      <p class="muted">* Tổng byte qua các giao diện (trừ loopback).</p>
    </div>
  </div>
</section>

<section id="sysinfo">
  <h2>Thông tin hệ thống</h2>
  <div class="grid two">
    <div class="card">
      <h3>Kernel & Uptime</h3>
      <pre class="pre">
Kernel: <?php echo php_uname('r'); ?>
Uptime: <?php echo trim(@file_get_contents('/proc/uptime')); ?> (giây)
PHP:    <?php echo PHP_VERSION; ?>
      </pre>
    </div>
    <div class="card">
      <h3>Server</h3>
      <pre class="pre">
Hostname: <?php echo gethostname(); ?>
SAPI:     <?php echo php_sapi_name(); ?>
      </pre>
    </div>
  </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>