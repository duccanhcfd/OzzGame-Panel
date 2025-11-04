<?php
// security.php
// Security Center — Firewall / Fail2Ban / ModSecurity / ClamAV / Logs
// PLACE this file under your OCCPanel folder (e.g. /var/www/html/occpanel/security.php)

// include hệ thống (để dùng auth, header, footer, config)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/header.php';

// Kiểm tra admin: điều chỉnh nếu hệ thống auth của bạn khác
function is_admin_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) return true;
    // nếu hệ thống của bạn lưu user object, hãy thêm check tương ứng ở đây
    if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin') return true;
    return false;
}
if (!is_admin_session()) {
    http_response_code(403);
    echo "<h3>Access denied. Admin only.</h3>";
    require_once __DIR__ . '/footer.php';
    exit;
}

// CSRF token
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['sec_csrf'])) $_SESSION['sec_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['sec_csrf'];

// Paths & files
$cfgDir = '/etc/occpanel';
$whitelistFile = $cfgDir . '/whitelist.txt';
$blacklistFile = $cfgDir . '/blacklist.txt';
$portsFile = __DIR__ . '/firewall_ports.txt'; // local file in occpanel to remember ports

// Ensure default ports file exists
if (!file_exists($portsFile)) file_put_contents($portsFile, "22,80,443");

// Detect whether server uses firewalld or ufw
$has_firewalld = trim(shell_exec('command -v firewall-cmd 2>/dev/null')) !== '';
$has_ufw = trim(shell_exec('command -v ufw 2>/dev/null')) !== '';
$fw_backend = $has_firewalld ? 'firewalld' : ($has_ufw ? 'ufw' : 'none');

// Helper — safer shell exec (we'll only call prepared commands)
function runCmd($cmd) {
    // basic safety: forbid obvious dangerous chars from dynamic input (we still carefully build commands)
    return shell_exec($cmd . " 2>&1");
}

// Validate IPv4 or IPv4/CIDR
function valid_ip_or_cidr($s) {
    $s = trim($s);
    if ($s === '') return false;
    if (filter_var($s, FILTER_VALIDATE_IP)) return true;
    if (strpos($s, '/') !== false) {
        list($ip, $prefix) = explode('/', $s, 2);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $p = intval($prefix);
        return ($p >= 0 && $p <= 32);
    }
    return false;
}

// Sanitize ports CSV → array of numbers
function parse_ports($csv) {
    $arr = preg_split('/[,\s]+/', trim($csv));
    $out = [];
    foreach ($arr as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^\d{1,5}$/', $p)) {
            $n = intval($p);
            if ($n > 0 && $n <= 65535) $out[] = $n;
        }
    }
    return array_values(array_unique($out));
}

// Apply ports for firewalld
function apply_ports_firewalld($ports, &$log) {
    // Read old ports from file (will be handled by PHP caller)
    // For each old port, remove --permanent --remove-port
    // For each new port, add --permanent --add-port
    foreach ($ports as $port) {
        $cmd = "sudo firewall-cmd --permanent --add-port=" . escapeshellarg($port . "/tcp");
        $log .= runCmd($cmd) . PHP_EOL;
    }
    // reload
    $log .= runCmd("sudo firewall-cmd --reload") . PHP_EOL;
}

// Remove specific permanent port with firewalld
function remove_ports_firewalld($ports, &$log) {
    foreach ($ports as $port) {
        $cmd = "sudo firewall-cmd --permanent --remove-port=" . escapeshellarg($port . "/tcp");
        $log .= runCmd($cmd) . PHP_EOL;
    }
    $log .= runCmd("sudo firewall-cmd --reload") . PHP_EOL;
}

// Apply ports for ufw
function apply_ports_ufw($ports, &$log) {
    $log .= runCmd("sudo ufw --force reset") . PHP_EOL;
    $log .= runCmd("sudo ufw default deny incoming") . PHP_EOL;
    $log .= runCmd("sudo ufw default allow outgoing") . PHP_EOL;
    $log .= runCmd("sudo ufw allow in on lo") . PHP_EOL;
    foreach ($ports as $port) {
        $log .= runCmd("sudo ufw allow " . intval($port) . "/tcp") . PHP_EOL;
    }
    $log .= runCmd("sudo ufw --force enable") . PHP_EOL;
}

// Apply whitelist/blacklist using backend
function apply_lists_firewalld($whitelist, $blacklist, &$log) {
    // Remove existing rich rules (we'll try remove using previous content if present)
    // For simplicity, first try to remove any rule matching these exact lines (idempotent)
    // Remove old rules for blacklist/whitelist: (we won't attempt to auto-detect all existing rules)
    foreach ($blacklist as $ip) {
        $rule = 'rule family="ipv4" source address="' . $ip . '" reject';
        $log .= runCmd("sudo firewall-cmd --permanent --remove-rich-rule=" . escapeshellarg($rule)) . PHP_EOL;
    }
    foreach ($whitelist as $ip) {
        $rule = 'rule family="ipv4" source address="' . $ip . '" accept';
        $log .= runCmd("sudo firewall-cmd --permanent --remove-rich-rule=" . escapeshellarg($rule)) . PHP_EOL;
    }
    // Add whitelist first (allow)
    foreach ($whitelist as $ip) {
        $rule = 'rule family="ipv4" source address="' . $ip . '" accept';
        $log .= runCmd("sudo firewall-cmd --permanent --add-rich-rule=" . escapeshellarg($rule)) . PHP_EOL;
    }
    // Add blacklist (reject)
    foreach ($blacklist as $ip) {
        $rule = 'rule family="ipv4" source address="' . $ip . '" reject';
        $log .= runCmd("sudo firewall-cmd --permanent --add-rich-rule=" . escapeshellarg($rule)) . PHP_EOL;
    }
    $log .= runCmd("sudo firewall-cmd --reload") . PHP_EOL;
}

function apply_lists_ufw($whitelist, $blacklist, &$log) {
    // UFW: whitelist => ufw allow from IP ; blacklist => ufw deny from IP to any
    // To avoid duplicates, remove exact matches first by 'delete allow from IP' but UFW delete syntax is index-based.
    // Simplify: add rules (ufw avoids duplicates in many cases). To remove outdated ones you'd need more complex logic.
    foreach ($whitelist as $ip) {
        $log .= runCmd("sudo ufw allow from " . escapeshellarg($ip)) . PHP_EOL;
    }
    foreach ($blacklist as $ip) {
        // Insert at top to take precedence
        $log .= runCmd("sudo ufw insert 1 deny from " . escapeshellarg($ip) . " to any") . PHP_EOL;
    }
    $log .= runCmd("sudo ufw reload") . PHP_EOL;
}

// APPLY actions based on POST
$tab = $_GET['tab'] ?? 'firewall';
$output = '';
$log = '';

// === FIREWALL TAB actions ===
if ($tab === 'firewall' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $CSRF) {
        $output = "CSRF check failed.";
    } else {
        // Save ports
        if (isset($_POST['fw_save'])) {
            $raw = $_POST['ports'] ?? '';
            $ports = parse_ports($raw);
            if (empty($ports)) {
                $output = "No valid ports provided.";
            } else {
                // read previous ports from file (if any) — attempt to remove them (firewalld path)
                $prev = [];
                if (file_exists($portsFile)) {
                    $prev = parse_ports(trim(file_get_contents($portsFile)));
                }
                // Save new ports
                file_put_contents($portsFile, implode(',', $ports));
                if ($fw_backend === 'firewalld') {
                    // remove previous ports first (safer)
                    if (!empty($prev)) remove_ports_firewalld($prev, $log);
                    apply_ports_firewalld($ports, $log);
                } elseif ($fw_backend === 'ufw') {
                    apply_ports_ufw($ports, $log);
                } else {
                    $log .= "No firewall backend detected (neither firewalld nor ufw). Install one.\n";
                }
                $output = $log ?: "Ports applied.";
            }
        }
        if (isset($_POST['fw_status'])) {
            if ($fw_backend === 'firewalld') {
                $output = runCmd("sudo firewall-cmd --list-all --permanent 2>/dev/null");
                $output .= PHP_EOL . runCmd("sudo firewall-cmd --list-all");
            } elseif ($fw_backend === 'ufw') {
                $output = runCmd("sudo ufw status verbose");
            } else {
                $output = "No firewall backend installed.";
            }
        }
        if (isset($_POST['fw_disable'])) {
            if ($fw_backend === 'firewalld') {
                $output = runCmd("sudo systemctl stop firewalld && sudo systemctl disable firewalld 2>&1");
            } elseif ($fw_backend === 'ufw') {
                $output = runCmd("sudo ufw disable 2>&1");
            } else {
                $output = "No firewall backend installed.";
            }
        }
    }
}

// === WHITELIST / BLACKLIST actions ===
if ($tab === 'lists' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $CSRF) {
        $output = "CSRF check failed.";
    } else {
        // Ensure config dir exists and files are writeable (PHP may not own /etc)
        if (!is_dir($cfgDir)) {
            // try create (if web user has no permission, instruct later)
            @mkdir($cfgDir, 0755, true);
        }
        // Save whitelist
        if (isset($_POST['save_whitelist'])) {
            $text = $_POST['whitelist'] ?? '';
            $lines = preg_split("/\r\n|\n|\r/", $text);
            $out = [];
            foreach ($lines as $l) {
                $l = trim($l);
                if ($l === '') continue;
                if (valid_ip_or_cidr($l)) $out[] = $l;
            }
            $data = implode("\n", $out) . (count($out) ? "\n" : "");
            if (@file_put_contents($whitelistFile, $data) === false) {
                $output = "Cannot write whitelist file. Check /etc/occpanel permissions.";
            } else {
                $output = "Whitelist saved.";
            }
        }
        // Save blacklist
        if (isset($_POST['save_blacklist'])) {
            $text = $_POST['blacklist'] ?? '';
            $lines = preg_split("/\r\n|\n|\r/", $text);
            $out = [];
            foreach ($lines as $l) {
                $l = trim($l);
                if ($l === '') continue;
                if (valid_ip_or_cidr($l)) $out[] = $l;
            }
            $data = implode("\n", $out) . (count($out) ? "\n" : "");
            if (@file_put_contents($blacklistFile, $data) === false) {
                $output = "Cannot write blacklist file. Check /etc/occpanel permissions.";
            } else {
                $output = "Blacklist saved.";
            }
        }

        // Apply lists immediately
        if (isset($_POST['apply_lists'])) {
            $wl = [];
            $bl = [];
            if (is_file($whitelistFile)) {
                $wl = array_values(array_filter(array_map('trim', explode("\n", file_get_contents($whitelistFile)))));
            }
            if (is_file($blacklistFile)) {
                $bl = array_values(array_filter(array_map('trim', explode("\n", file_get_contents($blacklistFile)))));
            }
            if ($fw_backend === 'firewalld') {
                apply_lists_firewalld($wl, $bl, $log);
                $output = $log ?: "Applied lists to firewalld.";
            } elseif ($fw_backend === 'ufw') {
                apply_lists_ufw($wl, $bl, $log);
                $output = $log ?: "Applied lists to ufw.";
            } else {
                $output = "No firewall backend to apply lists.";
            }
        }

        // Quick ban via adding to blacklist file + apply
        if (isset($_POST['quick_ban'])) {
            $ip = trim($_POST['quick_ban_ip'] ?? '');
            if (!valid_ip_or_cidr($ip)) $output = "Invalid IP/CIDR.";
            else {
                // append safely
                if (@file_put_contents($blacklistFile, $ip . "\n", FILE_APPEND) === false) {
                    $output = "Cannot append blacklist file.";
                } else {
                    // apply
                    $wl = is_file($whitelistFile) ? array_values(array_filter(array_map('trim', explode("\n", file_get_contents($whitelistFile))))) : [];
                    $bl = array_values(array_filter(array_map('trim', explode("\n", file_get_contents($blacklistFile)))));
                    if ($fw_backend === 'firewalld') {
                        apply_lists_firewalld($wl, $bl, $log);
                    } elseif ($fw_backend === 'ufw') {
                        apply_lists_ufw($wl, $bl, $log);
                    }
                    $output = "Banned $ip (added to blacklist). " . $log;
                }
            }
        }
        // Quick unban: remove line from blacklist and apply
        if (isset($_POST['quick_unban'])) {
            $ip = trim($_POST['quick_unban_ip'] ?? '');
            if (!valid_ip_or_cidr($ip)) $output = "Invalid IP/CIDR.";
            else {
                $bl = is_file($blacklistFile) ? array_values(array_filter(array_map('trim', explode("\n", file_get_contents($blacklistFile))))) : [];
                $bl = array_filter($bl, function($l) use ($ip) { return trim($l) !== $ip; });
                if (@file_put_contents($blacklistFile, implode("\n", $bl) . (count($bl)? "\n":'')) === false) {
                    $output = "Cannot update blacklist file.";
                } else {
                    // apply lists
                    $wl = is_file($whitelistFile) ? array_values(array_filter(array_map('trim', explode("\n", file_get_contents($whitelistFile))))) : [];
                    if ($fw_backend === 'firewalld') {
                        apply_lists_firewalld($wl, $bl, $log);
                    } elseif ($fw_backend === 'ufw') {
                        apply_lists_ufw($wl, $bl, $log);
                    }
                    $output = "Unbanned $ip. " . $log;
                }
            }
        }
    }
}

// === FAIL2BAN / BAN via fail2ban ===
if ($tab === 'fail2ban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $CSRF) {
        $output = "CSRF failed.";
    } else {
        if (isset($_POST['fb_start'])) $output = runCmd("sudo systemctl start fail2ban");
        if (isset($_POST['fb_stop'])) $output = runCmd("sudo systemctl stop fail2ban");
        if (isset($_POST['fb_status'])) $output = runCmd("sudo fail2ban-client status");
        if (isset($_POST['fb_ssh'])) $output = runCmd("sudo fail2ban-client status sshd");
        if (isset($_POST['fb_ban']) && !empty($_POST['fb_ban_ip'])) {
            $ip = trim($_POST['fb_ban_ip']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) $output = runCmd("sudo fail2ban-client set sshd banip " . escapeshellarg($ip));
            else $output = "Invalid IP.";
        }
        if (isset($_POST['fb_unban']) && !empty($_POST['fb_unban_ip'])) {
            $ip = trim($_POST['fb_unban_ip']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) $output = runCmd("sudo fail2ban-client set sshd unbanip " . escapeshellarg($ip));
            else $output = "Invalid IP.";
        }
    }
}

// === ModSecurity ===
if ($tab === 'modsecurity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $CSRF) {
        $output = "CSRF failed.";
    } else {
        if (isset($_POST['mod_enable'])) {
            // Install and enable ModSecurity (AlmaLinux)
            $output = runCmd("sudo dnf install -y mod_security mod_security_crs 2>&1");
            $output .= PHP_EOL . runCmd("sudo systemctl restart httpd 2>&1");
        }
        if (isset($_POST['mod_disable'])) {
            $output = runCmd("sudo dnf remove -y mod_security mod_security_crs 2>&1");
            $output .= PHP_EOL . runCmd("sudo systemctl restart httpd 2>&1");
        }
        if (isset($_POST['mod_status'])) {
            $output = runCmd("sudo httpd -M 2>&1 | grep -i security || true");
        }
    }
}

// === ClamAV ===
if ($tab === 'clamav' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $CSRF) {
        $output = "CSRF failed.";
    } else {
        if (isset($_POST['cl_install'])) {
            $output = runCmd("sudo dnf install -y clamav clamav-update 2>&1");
            $output .= PHP_EOL . runCmd("sudo freshclam 2>&1");
        }
        if (isset($_POST['cl_scan'])) {
            $path = trim($_POST['cl_path'] ?? '');
            // sanitize path
            $real = @realpath($path);
            if ($real === false) {
                $output = "Invalid path.";
            } else {
                // optional: restrict scanning to /home or /var/www to prevent heavy full disk scan
                // if you want to restrict uncomment below:
                // if (strpos($real, '/home') !== 0 && strpos($real, '/var/www') !== 0) { $output = "Scanning limited to /home or /var/www"; }
                $output = runCmd("sudo clamscan -r --bell -i " . escapeshellarg($real));
            }
        }
    }
}

// === Logs ===
if ($tab === 'logs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $CSRF) {
        $output = "CSRF failed.";
    } else {
        if (isset($_POST['lg_apache'])) $output = runCmd("sudo tail -n 100 /var/log/httpd/error_log");
        if (isset($_POST['lg_access'])) $output = runCmd("sudo tail -n 100 /var/log/httpd/access_log");
        if (isset($_POST['lg_secure'])) $output = runCmd("sudo tail -n 200 /var/log/secure");
        if (isset($_POST['lg_mail'])) $output = runCmd("sudo tail -n 200 /var/log/maillog");
        if (isset($_POST['lg_journal'])) $output = runCmd("sudo journalctl -n 200 --no-pager");
    }
}

// Load files for display
$ports_current = htmlspecialchars(trim(file_get_contents($portsFile)));
$whitelist_text = is_file($whitelistFile) ? htmlspecialchars(file_get_contents($whitelistFile)) : "";
$blacklist_text = is_file($blacklistFile) ? htmlspecialchars(file_get_contents($blacklistFile)) : "";

?>
<!-- UI: use the OCCPanel header/footer and simple bootstrap-like tabs if available -->
<div class="container" style="padding:15px;">
    <h2>Security Center</h2>
    <p>Backend firewall: <strong><?= htmlspecialchars($fw_backend) ?></strong></p>

    <!-- tab menu -->
    <ul class="nav nav-tabs" style="margin-bottom:10px;">
        <li class="nav-item"><a class="nav-link <?= $tab==='firewall'?'active':'' ?>" href="?tab=firewall">Firewall</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='lists'?'active':'' ?>" href="?tab=lists">Whitelist / Blacklist</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='fail2ban'?'active':'' ?>" href="?tab=fail2ban">Fail2Ban</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='modsecurity'?'active':'' ?>" href="?tab=modsecurity">ModSecurity (WAF)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='clamav'?'active':'' ?>" href="?tab=clamav">ClamAV</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='logs'?'active':'' ?>" href="?tab=logs">Logs</a></li>
    </ul>

    <div class="card p-3 bg-light">
    <!-- FIREWALL -->
    <?php if ($tab === 'firewall'): ?>
        <h4>Firewall — ports</h4>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <label>Open TCP ports (comma separated):</label><br>
            <input type="text" name="ports" size="60" value="<?= $ports_current ?>"><br><br>
            <button name="fw_save" class="btn">Save & Apply</button>
            <button name="fw_status" class="btn">Show Status</button>
            <button name="fw_disable" class="btn" onclick="return confirm('Disable firewall? (Dangerous)')">Disable Firewall</button>
        </form>
        <hr>
        <p><small>Note: on AlmaLinux default backend is <strong>firewalld</strong>. If you prefer <code>ufw</code> you can install it but firewalld is recommended.</small></p>
    <?php endif; ?>

    <!-- LISTS -->
    <?php if ($tab === 'lists'): ?>
        <h4>Whitelist (allow)</h4>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <textarea name="whitelist" rows="6" cols="80" placeholder="One IP or CIDR per line"><?= $whitelist_text ?></textarea><br>
            <button name="save_whitelist" class="btn">Save Whitelist</button>
        </form>
        <hr>
        <h4>Blacklist (block)</h4>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <textarea name="blacklist" rows="6" cols="80" placeholder="One IP or CIDR per line"><?= $blacklist_text ?></textarea><br>
            <button name="save_blacklist" class="btn">Save Blacklist</button>
        </form>
        <br>
        <form method="post" style="display:inline-block">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <button name="apply_lists" class="btn">Apply Lists to Firewall Now</button>
        </form>

        <hr>
        <h5>Quick ban / unban</h5>
        <form method="post" style="display:inline-block;margin-right:10px">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            IP/CIDR: <input name="quick_ban_ip"> <button name="quick_ban" class="btn">Ban (add to blacklist)</button>
        </form>
        <form method="post" style="display:inline-block">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            IP/CIDR: <input name="quick_unban_ip"> <button name="quick_unban" class="btn">Unban</button>
        </form>
    <?php endif; ?>

    <!-- FAIL2BAN -->
    <?php if ($tab === 'fail2ban'): ?>
        <h4>Fail2Ban</h4>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <button name="fb_start" class="btn">Start</button>
            <button name="fb_stop" class="btn">Stop</button>
            <button name="fb_status" class="btn">Status</button>
            <button name="fb_ssh" class="btn">SSH Jail Status</button>
        </form>
        <hr>
        <h5>Manual ban/unban (sshd jail)</h5>
        <form method="post" style="display:inline-block;margin-right:10px">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            IP: <input name="fb_ban_ip"> <button name="fb_ban" class="btn">Ban via Fail2Ban</button>
        </form>
        <form method="post" style="display:inline-block">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            IP: <input name="fb_unban_ip"> <button name="fb_unban" class="btn">Unban via Fail2Ban</button>
        </form>
    <?php endif; ?>

    <!-- MODSECURITY -->
    <?php if ($tab === 'modsecurity'): ?>
        <h4>ModSecurity (WAF)</h4>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <button name="mod_enable" class="btn">Install & Enable ModSecurity + CRS</button>
            <button name="mod_disable" class="btn" onclick="return confirm('Disable and remove ModSecurity?')">Disable/Remove</button>
            <button name="mod_status" class="btn">Status</button>
        </form>
        <p><small>Installing CRS may require tuning for your apps. Test on staging before enabling on production.</small></p>
    <?php endif; ?>

    <!-- CLAMAV -->
    <?php if ($tab === 'clamav'): ?>
        <h4>ClamAV</h4>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <button name="cl_install" class="btn">Install/Update ClamAV</button>
        </form>
        <hr>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <label>Path to scan:</label>
            <input type="text" name="cl_path" size="60" value="/home"><br><br>
            <button name="cl_scan" class="btn">Scan (recursive)</button>
        </form>
        <p><small>Warning: scanning large trees is heavy. Prefer scanning specific user dirs.</small></p>
    <?php endif; ?>

    <!-- LOGS -->
    <?php if ($tab === 'logs'): ?>
        <h4>System Logs</h4>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <button name="lg_apache" class="btn">Apache Errors (tail)</button>
            <button name="lg_access" class="btn">Apache Access (tail)</button>
            <button name="lg_secure" class="btn">Auth / Secure Log (tail)</button>
            <button name="lg_mail" class="btn">Mail Log (tail)</button>
            <button name="lg_journal" class="btn">Journalctl (tail)</button>
        </form>
    <?php endif; ?>

    <hr>
    <?php if (!empty($output)): ?>
        <h5>Output</h5>
        <pre style="white-space:pre-wrap;max-height:500px;overflow:auto;border:1px solid #ddd;padding:8px;"><?= htmlspecialchars($output) ?></pre>
    <?php endif; ?>

    </div>
</div>

<?php
require_once __DIR__ . '/footer.php';