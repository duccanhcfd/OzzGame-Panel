<?php
require_once __DIR__ . '/panel/config.php';

/**
 * Tạo user hệ thống cho domain
 */
function create_system_user($username) {
    $exists = shell_exec("id -u $username 2>/dev/null");
    if ($exists) return false;

    exec("useradd -m -d /var/www/$username -s /bin/bash $username");
    exec("mkdir -p /var/www/$username/public_html");
    exec("mkdir -p /var/www/$username/logs");
    exec("chown -R $username:$username /var/www/$username");

    return true;
}

/**
 * Tạo thư mục domain (dùng user hệ thống)
 */
function create_domain_dir($domain, $user = 'root') {
    $base_dir = '/var/www/';
    $domain_dir = $base_dir . $domain;

    if (!is_dir($domain_dir)) {
        mkdir($domain_dir, 0755, true);
        mkdir($domain_dir . '/public_html', 0755);
        mkdir($domain_dir . '/logs', 0755);
        exec("chown -R $user:$user $domain_dir");
        return true;
    }
    return false;
}

/**
 * Tạo VirtualHost Apache và reload ngay
 */
function create_vhost($domain, $user = 'root') {
    $vhost_file = "/etc/httpd/conf.d/$domain.conf";
    $public_dir = "/var/www/$domain/public_html";

    $content = "
<VirtualHost *:80>
    ServerName $domain
    DocumentRoot $public_dir
    ErrorLog /var/www/$domain/logs/error.log
    CustomLog /var/www/$domain/logs/access.log combined
    <Directory $public_dir>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
    ";

    file_put_contents($vhost_file, $content);
    exec("systemctl reload httpd");
}

/**
 * Tạo database + user MySQL
 */
function create_database($dbname, $dbuser, $dbpass) {
    global $pdo;
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname`");
    $pdo->exec("CREATE USER IF NOT EXISTS '$dbuser'@'localhost' IDENTIFIED BY '$dbpass'");
    $pdo->exec("GRANT ALL PRIVILEGES ON `$dbname`.* TO '$dbuser'@'localhost'");
    $pdo->exec("FLUSH PRIVILEGES");
}

/**
 * Thêm domain vào bảng panel_database
 */
function add_domain_panel($domain, $user_id) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO panel_database (domain, user_id) VALUES (?, ?)");
    $stmt->execute([$domain, $user_id]);
}

/**
 * Thêm database vào bảng panel_database
 */
function add_database_panel($dbname, $dbuser, $dbpass) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO panel_database (dbname, dbuser, dbpass) VALUES (?, ?, ?)");
    $stmt->execute([$dbname, $dbuser, $dbpass]);
}