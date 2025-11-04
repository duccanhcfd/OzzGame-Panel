<?php
require_once __DIR__ . '/header.php';  // dùng header panel
echo "<div class='content-card' style='max-width:800px;margin:20px auto;padding:20px;background:#111b2d;border-radius:12px;color:#e7ecfa;'>";

echo "<h1 style='text-align:center;color:#3b82f6;'>ComZPanel Security Scanner</h1>";

$results = [];

// --- 1. HTTP headers check ---
$target = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/login.php';
$ch = curl_init($target);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
$response = curl_exec($ch);
$headers = [];
if ($response !== false) {
    foreach (explode("\r\n", $response) as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(":", $line, 2);
            $headers[trim($key)] = trim($value);
        }
    }
}
curl_close($ch);

$securityHeaders = [
    'X-Frame-Options' => 'Clickjacking protection',
    'X-XSS-Protection' => 'XSS filter',
    'X-Content-Type-Options' => 'MIME sniffing protection',
    'Content-Security-Policy' => 'CSP',
    'Strict-Transport-Security' => 'HSTS'
];

foreach ($securityHeaders as $h => $desc) {
    if (isset($headers[$h])) {
        $results[$desc] = ['status' => 'Safe', 'detail' => $headers[$h]];
    } else {
        $results[$desc] = ['status' => 'Warning', 'detail' => 'Missing header'];
    }
}

// --- 2. Session cookie check ---
session_start();
$cookieSecure = isset($_SERVER['HTTPS']) ? true : false;
$cookieHttpOnly = ini_get('session.cookie_httponly') ? true : false;
$cookieSameSite = ini_get('session.cookie_samesite') ? ini_get('session.cookie_samesite') : 'None';

$results['Session cookie Secure'] = $cookieSecure ? ['status'=>'Safe','detail'=>'Yes'] : ['status'=>'Warning','detail'=>'No'];
$results['Session cookie HttpOnly'] = $cookieHttpOnly ? ['status'=>'Safe','detail'=>'Yes'] : ['status'=>'Warning','detail'=>'No'];
$results['Session cookie SameSite'] = $cookieSameSite == 'Strict' || $cookieSameSite == 'Lax' ? ['status'=>'Safe','detail'=>$cookieSameSite] : ['status'=>'Warning','detail'=>$cookieSameSite];

// --- 3. Login form existence ---
$loginPage = @file_get_contents($target);
if ($loginPage && strpos($loginPage, '<form') !== false) {
    $results['Login form'] = ['status'=>'Safe','detail'=>'Found'];
} else {
    $results['Login form'] = ['status'=>'Dangerous','detail'=>'Login page missing or inaccessible'];
}

// --- 4. Directory listing check ---
$dirFiles = scandir(__DIR__);
$results['Directory listing protection'] = in_array('index.php', $dirFiles) ? ['status'=>'Safe','detail'=>'index.php exists'] : ['status'=>'Warning','detail'=>'Directory may be exposed'];

// --- 5. SSL/TLS check (HTTPS only) ---
if (isset($_SERVER['HTTPS'])) {
    $urlParts = parse_url($target);
    $host = $urlParts['host'];
    $sslCheck = stream_context_create(["ssl" => ["capture_peer_cert" => true]]);
    $stream = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $sslCheck);
    if ($stream) {
        $context = stream_context_get_params($stream);
        $cert = openssl_x509_parse($context["options"]["ssl"]["peer_certificate"]);
        $results['SSL Certificate Validity'] = ['status'=>'Safe','detail'=>'Expires: '.date('Y-m-d',$cert['validTo_time_t'])];
    } else {
        $results['SSL Certificate'] = ['status'=>'Warning','detail'=>"Cannot connect to SSL"];
    }
}

// --- 6. Display results ---
echo "<table style='width:100%;border-collapse:collapse;margin-top:20px;'>";
echo "<tr style='background:#1f2c45;'><th style='padding:10px;border:1px solid #2563eb;'>Check</th><th style='padding:10px;border:1px solid #2563eb;'>Status</th><th style='padding:10px;border:1px solid #2563eb;'>Detail / Suggestion</th></tr>";
foreach ($results as $check => $info) {
    $color = '#e7ecfa';
    if($info['status']=='Safe') $color='green';
    elseif($info['status']=='Warning') $color='orange';
    elseif($info['status']=='Dangerous') $color='red';
    echo "<tr style='border:1px solid #2563eb;'><td style='padding:8px;'>$check</td><td style='padding:8px;color:$color;font-weight:bold;'>{$info['status']}</td><td style='padding:8px;'>{$info['detail']}</td></tr>";
}
echo "</table>";

echo "</div>";
require_once __DIR__ . '/footer.php'; // dùng footer panel