<?php
// host_functions.php

// ================== DB CONNECT ==================
function db_connect(): PDO {
    static $pdo;
    if ($pdo) return $pdo;

    require __DIR__ . '/config.php'; // biến $pdo có sẵn ở đây
    return $pdo;
}

// ================== DOMAIN VALIDATION ==================
function validate_domain(string $domain): bool {
    return (bool)preg_match('/^(?!-)([a-zA-Z0-9-]{1,63}\.)+[a-zA-Z]{2,}$/', $domain);
}

// ================== API TOKEN ==================
function get_user_by_token(string $token): ?array {
    $pdo = db_connect();
    $sql = "SELECT username, is_active, is_admin, expires_at FROM api_keys WHERE token = :t LIMIT 1";
    $st  = $pdo->prepare($sql);
    $st->execute([':t'=>$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if (!$r['is_active']) return null;
    if ($r['expires_at'] && strtotime($r['expires_at']) < time()) return null;
    return $r;
}

// ================== HOST FUNCTIONS ==================
function get_all_hosts(): array {
    $pdo = db_connect();
    $sql = "SELECT id, username, domain, docroot, password, ip_temp, created_at, status FROM hosts ORDER BY created_at DESC";
    $st = $pdo->query($sql);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function get_hosts_by_user(string $username): array {
    $pdo = db_connect();
    $sql = "SELECT id, domain, docroot, password, ip_temp, created_at, status FROM hosts WHERE username = :u ORDER BY created_at DESC";
    $st = $pdo->prepare($sql);
    $st->execute([':u'=>$username]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ================== CREATE HOST ==================
function create_host(string $username, string $domain, ?string $docroot_base = null, ?string $password = null, ?string $ip_temp = null): array {
    $pdo = db_connect();

    if (!validate_domain($domain)) {
        throw new InvalidArgumentException("Invalid domain");
    }

    $base    = $docroot_base ?? '/var/www/html/panel/hosts';
    $docroot = rtrim($base, '/') . '/' . $domain . '/public_html';
    $password = $password ?? '123456';
    $ip_temp  = $ip_temp  ?? '0.0.0.0';

    // check domain đã tồn tại chưa
    $st = $pdo->prepare("SELECT id FROM hosts WHERE domain = :d LIMIT 1");
    $st->execute([':d'=>$domain]);
    if ($st->fetch()) {
        throw new RuntimeException("Domain already exists");
    }

    // insert vào DB
    $ins = $pdo->prepare("
        INSERT INTO hosts (username, domain, docroot, status, password, ip_temp)
        VALUES (:u, :d, :r, 'pending', :p, :ip)
    ");
    $ins->execute([
        ':u'  => $username,
        ':d'  => $domain,
        ':r'  => $docroot,
        ':p'  => $password,
        ':ip' => $ip_temp
    ]);

    $hostId = (int)$pdo->lastInsertId();

    return ['id'=>$hostId, 'username'=>$username, 'domain'=>$domain, 'docroot'=>$docroot, 'password'=>$password, 'ip_temp'=>$ip_temp, 'status'=>'pending'];
}

// ================== DELETE HOST ==================
function delete_host(string $username, string $domain): bool {
    $pdo = db_connect();
    $st = $pdo->prepare("SELECT id FROM hosts WHERE username = :u AND domain = :d LIMIT 1");
    $st->execute([':u'=>$username, ':d'=>$domain]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("Domain not found or not owned by user");

    $del = $pdo->prepare("DELETE FROM hosts WHERE id = :id");
    $del->execute([':id'=>$row['id']]);
    return true;
}

// ================== DELETE ANY HOST (ADMIN) ==================
function delete_host_any(string $domain): bool {
    $pdo = db_connect();
    $st = $pdo->prepare("SELECT id FROM hosts WHERE domain = :d LIMIT 1");
    $st->execute([':d'=>$domain]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException("Domain not found");

    $del = $pdo->prepare("DELETE FROM hosts WHERE id = :id");
    $del->execute([':id'=>$row['id']]);
    return true;
}
?>