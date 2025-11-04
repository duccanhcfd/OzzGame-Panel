#!/bin/bash
set -euo pipefail
echo "=== OCCPanel Full Installer - Nong Duc Canh ==="
# ========================================
# 0. Swap
# ========================================
echo "[0/15] Creating 2GB swap file (if not exists)..."
if [ ! -f /swapfile ]; then
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
# ========================================
# 0. System prerequisites
# ========================================
echo "[0/15] Installing prerequisites..."
mkdir -p /etc/httpd/system-conf.d
chown root:root /etc/httpd/system-conf.d
chmod 755 /etc/httpd/system-conf.d
dnf -y install epel-release
dnf -y install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf module reset php -y
dnf module enable php:remi-8.2 -y
dnf -y install httpd mariadb-server unzip wget bind-utils curl sudo firewalld \
php php-mysqlnd php-fpm php-cli php-zip php-json php-mbstring php-gd php-curl php-imap \
postfix dovecot pdns pdns-backend-mysql
# ========================================
# 1. Enable & start services
# ========================================
echo "[1/15] Enabling & starting services..."
systemctl enable --now httpd mariadb php-fpm firewalld postfix dovecot pdns
echo "apache ALL=(ALL) NOPASSWD: /bin/systemctl reload httpd" > /etc/sudoers.d/comzpanel
chmod 440 /etc/sudoers.d/comzpanel
visudo -c
# ========================================
# 2. MariaDB setup
# ========================================
echo "[2/15] Checking MariaDB root access..."
if ! mysql -u root -e "SELECT 1;" >/dev/null 2>&1; then
echo "MariaDB root access failed!"
exit 1
fi
echo "-> Root MariaDB OK"
echo "[3/15] Creating database & user for ComZPanel..."
mysql -u root <<'SQL'
-- Xóa nếu đã tồn tại
DROP DATABASE IF EXISTS comzpanel;
CREATE DATABASE comzpanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
DROP USER IF EXISTS 'comzpanel_user'@'localhost';
CREATE USER 'comzpanel_user'@'localhost' IDENTIFIED BY 'comzpanel@';
-- Cấp quyền toàn quyền trên database ComZPanel
GRANT ALL PRIVILEGES ON comzpanel.* TO 'comzpanel_user'@'localhost';
-- Cấp quyền global để tạo/xóa database và user
GRANT ALL PRIVILEGES ON *.* TO 'comzpanel_user'@'localhost' WITH GRANT OPTION;
-- Cho phép xem danh sách user (để hiển thị panel)
GRANT SELECT ON mysql.user TO 'comzpanel_user'@'localhost';
-- Áp dụng quyền
FLUSH PRIVILEGES;
SQL
# ========================================
# 3. ComZPanel panel setup
# ========================================
PANEL_DIR="/var/www/html/panel"
mkdir -p "$PANEL_DIR"
chown apache:apache "$PANEL_DIR"
chmod 775 "$PANEL_DIR"
CONFIG_FILE="$PANEL_DIR/config.php"
if [ ! -f "$CONFIG_FILE" ]; then
cat > "$CONFIG_FILE" <<'EOF'
<?php
$host = 'localhost';
$db = 'comzpanel';
$user = 'comzpanel_user';
$pass = 'comzpanel@';
try {
            $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
                        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
            die("Kết nối database thất bại: " . $e->getMessage());
}
?>
EOF
chown apache:apache "$CONFIG_FILE"
chmod 640 "$CONFIG_FILE"
fi
# ========================================
# 4. Import schema
# ========================================
echo "[4/15] Importing OCCPanel schema..."
cat > /tmp/comzpanel.sql <<'SQL'
CREATE TABLE IF NOT EXISTS hosts (
            id int(11) NOT NULL AUTO_INCREMENT,
                        domain varchar(255) NOT NULL,
                                        username varchar(32) NOT NULL,
                                                            password varchar(64) NOT NULL,
                                                                                    ip_temp varchar(45) NOT NULL,
                                                                                                                disk_quota int(11) DEFAULT 1024,
                                                                                                                                                bandwidth int(11) DEFAULT 10240,
                                                                                                                                                                                    database_count int(11) DEFAULT 1,
                                                                                                                                                                                                                            addon_count int(11) DEFAULT 0,
                                                                                                                                                                                                                                                                        parked_count int(11) DEFAULT 0,
                                                                                                                                                                                                                                                                                                                        email_count int(11) DEFAULT 1,
                                                                                                                                                                                                                                                                                                                                                                            created_at timestamp NOT NULL DEFAULT current_timestamp(),
                                                                                                                                                                                                                                                                                                                                                                                                                                    PRIMARY KEY (id),
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                UNIQUE KEY domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS users (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        username varchar(64) NOT NULL,
                                        password_hash varchar(255) NOT NULL,
                                                            created_at timestamp NOT NULL DEFAULT current_timestamp(),
                                                                                    PRIMARY KEY (id),
                                                                                                                UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO users (username, password_hash, created_at)
VALUES ('admin', '$2y$10$q8seecTqafCNsk4bncSy6er8Ddgj5gFuoWhN6EX0DhDdhqWBL0H.a', NOW())
ON DUPLICATE KEY UPDATE username=username;
CREATE TABLE IF NOT EXISTS emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
                        domain VARCHAR(255) NOT NULL,
                                        email VARCHAR(255) NOT NULL,
                                                            password VARCHAR(255) NOT NULL,
                                                                                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cronjobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
                        user VARCHAR(64) NOT NULL,
                                        schedule VARCHAR(64) NOT NULL,
                                                            command TEXT NOT NULL,
                                                                                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Bổ sung thêm các bảng còn thiếu
CREATE TABLE IF NOT EXISTS api_keys (
              id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                                username VARCHAR(100) NOT NULL COMMENT 'Tên người dùng hoặc mô tả token',
                                                            token VARCHAR(32) NOT NULL COMMENT 'Token ngắn 8 ký tự alphanumeric',
                                                                                                    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Trạng thái hoạt động: 1=active, 0=inactive',
                                                                                                                                                          expires_at DATETIME DEFAULT NULL COMMENT 'Ngày hết hạn token, nếu có',
                                                                                                                                                                                                                                created_at DATETIME NOT NULL DEFAULT current_timestamp() COMMENT 'Ngày tạo token',
                                                                                                                                                                                                                                                                                                                        PRIMARY KEY (id),
                                                                                                                                                                                                                                                                                                                                                                                                                                    UNIQUE KEY token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS failed_logins (
              id INT(11) NOT NULL AUTO_INCREMENT,
                                ip VARCHAR(45) NOT NULL,
                                                            attempt_time INT(11) NOT NULL,
                                                                                                    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
mysql -u root comzpanel < /tmp/comzpanel.sql
rm -f /tmp/comzpanel.sql
# ========================================
# ========================================
# 4b. Tạo bảng VPS container
# ========================================
echo "[4b/15] Creating VPS container table..."
cat > /tmp/comzpanel_vps.sql <<'SQL'
CREATE TABLE IF NOT EXISTS vps (
            id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(64) NOT NULL,
                                        os VARCHAR(32) NOT NULL,
                                                            ram VARCHAR(16) NOT NULL,
                                                                                    cpu VARCHAR(8) NOT NULL,
                                                                                                                disk VARCHAR(16) NOT NULL,
                                                                                                                                                port_ssh INT NOT NULL,
                                                                                                                                                                                    port_web INT NOT NULL,
                                                                                                                                                                                                                            ssh_user VARCHAR(32) NOT NULL,
                                                                                                                                                                                                                                                                        ssh_pass VARCHAR(64) NOT NULL,
                                                                                                                                                                                                                                                                                                                        web_url VARCHAR(255) NOT NULL,
                                                                                                                                                                                                                                                                                                                                                                            status ENUM('running','stopped') NOT NULL DEFAULT 'running',
                                                                                                                                                                                                                                                                                                                                                                                                                                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
mysql -u root comzpanel < /tmp/comzpanel_vps.sql
rm -f /tmp/comzpanel_vps.sql
# ========================================
# 4c. Cài Docker & chuẩn bị để tạo VPS container
# ========================================
echo "[4c/15] Installing Docker..."
dnf -y install dnf-plugins-core
dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
dnf -y install docker-ce docker-ce-cli containerd.io
systemctl enable --now docker
# Thêm apache vào group docker để PHP có thể gọi docker
usermod -aG docker apache
# Kiểm tra Docker
docker --version || { echo "Docker install failed!"; exit 1; }
# Tạo thư mục lưu container data nếu cần
mkdir -p /var/lib/comzpanel/containers
chown -R apache:apache /var/lib/comzpanel/containers
chmod -R 775 /var/lib/comzpanel/containers
echo "[*] VPS container table & Docker ready."
# ========================================
# 4d. Tạo VPS test root/root mặc định
# ========================================
echo "[4d/15] Creating default root/root test VPS..."
DEFAULT_VPS_NAME="testvps"
DEFAULT_SSH_PORT=2222
DEFAULT_WEB_PORT=8080
docker pull rastasheep/ubuntu-sshd:latest
# Nếu container trùng tên thì xóa trước
if docker ps -a --format '{{.Names}}' | grep -q "^$DEFAULT_VPS_NAME$"; then
    echo "Container $DEFAULT_VPS_NAME đã tồn tại, xóa..."
        docker rm -f $DEFAULT_VPS_NAME
        fi
        # Chạy container root/root
        docker run -d --name $DEFAULT_VPS_NAME -p $DEFAULT_SSH_PORT:22 -p $DEFAULT_WEB_PORT:80 rastasheep/ubuntu-sshd:latest
        echo "-> VPS $DEFAULT_VPS_NAME tạo xong! SSH root/root Port $DEFAULT_SSH_PORT"
        # 5. Deploy OCCPanel source
        # ========================================
        echo "[5/15] Deploying OCCPanel source..."
        cd "$PANEL_DIR"
        wget -O occ.zip https://panel.ozzgame.com/occ/occ.zip
        unzip -q occ.zip
        rm -f occ.zip
        chown -R apache:apache "$PANEL_DIR"
        mkdir -p "$PANEL_DIR/hosts"
        chown -R apache:apache "$PANEL_DIR/hosts"
        chmod -R 775 "$PANEL_DIR/hosts"
        cat > /etc/httpd/system-conf.d/panel.conf <<EOF
        Listen 2082
        <VirtualHost *:2082>
        ServerName panel.local
        DocumentRoot "$PANEL_DIR"
        <Directory "$PANEL_DIR">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
        </Directory>
        </VirtualHost>
EOF
        mkdir -p /var/www/html/conf.d
        chown apache:apache /var/www/html/conf.d
        chmod 775 /var/www/html/conf.d
        # Add include to httpd.conf if missing
        grep -q "IncludeOptional /etc/httpd/system-conf.d/*.conf" /etc/httpd/conf/httpd.conf || \
        {
                    echo "" >> /etc/httpd/conf/httpd.conf
                                echo "# System configurations - DO NOT EDIT" >> /etc/httpd/conf/httpd.conf
                                                echo "IncludeOptional /etc/httpd/system-conf.d/*.conf" >> /etc/httpd/conf/httpd.conf
                                                                    echo "" >> /etc/httpd/conf/httpd.conf
                                                                                            echo "# User virtual host configurations" >> /etc/httpd/conf/httpd.conf
                                                                                                                        echo "IncludeOptional /var/www/html/conf.d/*.conf" >> /etc/httpd/conf/httpd.conf
        }
        # ========================================
        # 6. Composer
        # ========================================
        echo "[6/15] Composer & PHP dependencies..."
        if [ -f "$PANEL_DIR/composer.json" ]; then
        if ! command -v composer >/dev/null 2>&1; then
        echo "Installing Composer..."
        cd /usr/local/bin
        curl -sS https://getcomposer.org/installer | php
        mv composer.phar composer
        chmod +x composer
        fi
        composer --version
        cd "$PANEL_DIR"
        composer install
        chown -R apache:apache "$PANEL_DIR/vendor"
        chmod -R 755 "$PANEL_DIR/vendor"
        else
        echo "[*] No composer.json found. Skipping Composer install."
        fi
        # ========================================
        # 7. phpMyAdmin
        # ========================================
        echo "[7/15] Installing phpMyAdmin..."
        PMA_DIR="/var/www/html/phpmyadmin"
        mkdir -p "$PMA_DIR"
        cd /var/www/html
        wget -O phpmyadmin.zip https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.zip
        unzip -q phpmyadmin.zip
        rm -f phpmyadmin.zip
        rm -rf phpmyadmin
        mv phpMyAdmin-*-all-languages phpmyadmin
        chown -R apache:apache /var/www/html/phpmyadmin
        chmod -R 755 /var/www/html/phpmyadmin
        # Apache config for phpMyAdmin
        mkdir -p /etc/httpd/conf-phpmyadmin.d
        cat > /etc/httpd/conf-phpmyadmin.d/phpmyadmin.conf <<'EOF'
        Alias /phpmyadmin /var/www/html/phpmyadmin
        <Directory /var/www/html/phpmyadmin>
        Options None
        AllowOverride All
        Require all granted
        </Directory>
EOF
        grep -q "conf-phpmyadmin.d" /etc/httpd/conf/httpd.conf || \
        echo "IncludeOptional /etc/httpd/conf-phpmyadmin.d/*.conf" >> /etc/httpd/conf/httpd.conf
        systemctl reload httpd
        # ========================================
        # 8. PowerDNS setup
        # ========================================
        echo "[8/15] Installing PowerDNS with MySQL backend..."
        dnf -y install pdns pdns-backend-mysql
        mysql -u root comzpanel <<'EOF'
        CREATE TABLE IF NOT EXISTS domains (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                                name VARCHAR(255) NOT NULL,
                                                master VARCHAR(128),
                                                                    last_check INT,
                                                                                            type VARCHAR(6) NOT NULL,
                                                                                                                        notified_serial INT,
                                                                                                                                                        account VARCHAR(40)
        ) ENGINE=InnoDB;
        CREATE TABLE IF NOT EXISTS records (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                                domain_id INT,
                                                name VARCHAR(255),
                                                                    type VARCHAR(10),
                                                                                            content TEXT,
                                                                                                                        ttl INT,
                                                                                                                                                        prio INT,
                                                                                                                                                                                            change_date INT,
                                                                                                                                                                                                                                    disabled TINYINT(1) DEFAULT 0,
                                                                                                                                                                                                                                                                                ordername VARCHAR(255) BINARY,
                                                                                                                                                                                                                                                                                                                                auth TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB;
EOF
        systemctl enable --now pdns
        # ========================================
        # 9. Mail server (Postfix/Dovecot)
        # ========================================
        echo "[9/15] Setting up Postfix/Dovecot..."
        id -u vmail &>/dev/null || useradd -r -u 5000 -g mail -d /var/vmail -m -s /sbin/nologin vmail
        mkdir -p /var/vmail
        chown -R vmail:mail /var/vmail
        mysql -u root comzpanel <<'EOF'
        CREATE TABLE IF NOT EXISTS virtual_domains (
                    id INT NOT NULL AUTO_INCREMENT,
                                name VARCHAR(50) NOT NULL,
                                                PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        CREATE TABLE IF NOT EXISTS virtual_users (
                    id INT NOT NULL AUTO_INCREMENT,
                                domain_id INT NOT NULL,
                                                password VARCHAR(106) NOT NULL,
                                                                    email VARCHAR(100) NOT NULL,
                                                                                            PRIMARY KEY (id),
                                                                                                                        UNIQUE KEY email (email),
                                                                                                                                                        FOREIGN KEY (domain_id) REFERENCES virtual_domains(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        CREATE TABLE IF NOT EXISTS virtual_aliases (
                    id INT NOT NULL AUTO_INCREMENT,
                                domain_id INT NOT NULL,
                                                source VARCHAR(100) NOT NULL,
                                                                    destination VARCHAR(100) NOT NULL,
                                                                                            PRIMARY KEY (id),
                                                                                                                        FOREIGN KEY (domain_id) REFERENCES virtual_domains(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
EOF
        # Configure Submission/SMTPS
        MASTER_CF="/etc/postfix/master.cf"
        grep -q "^submission" $MASTER_CF || echo "submission inet n - n - - smtpd" >> $MASTER_CF
        grep -q "^smtps" $MASTER_CF || echo "smtps inet n - n - - smtpd" >> $MASTER_CF
        postconf -e 'smtpd_tls_cert_file=/etc/pki/tls/certs/localhost.crt'
        postconf -e 'smtpd_tls_key_file=/etc/pki/tls/private/localhost.key'
        postconf -e 'smtpd_use_tls=yes'
        sed -i 's/^inet_interfaces\s*=.*$/inet_interfaces = all/' /etc/postfix/main.cf
        systemctl restart postfix
        # ========================================
        # 10. Firewall
        # ========================================
        echo "[10/15] Configuring firewall..."
        systemctl enable --now firewalld
        firewall-cmd --permanent --add-port=80/tcp
        firewall-cmd --permanent --add-port=443/tcp
        firewall-cmd --permanent --add-port=2082/tcp
        firewall-cmd --permanent --add-port=53/tcp
        firewall-cmd --permanent --add-port=53/udp
        firewall-cmd --permanent --add-service=smtp
        firewall-cmd --permanent --add-port=465/tcp
        firewall-cmd --permanent --add-port=587/tcp
        firewall-cmd --permanent --add-service=imap
        firewall-cmd --permanent --add-service=pop3
        firewall-cmd --permanent --add-port=993/tcp
        firewall-cmd --permanent --add-port=995/tcp
        firewall-cmd --reload
        # ========================================
        # 11. SELinux
        # ========================================
        if command -v sestatus >/dev/null 2>&1 && sestatus | grep -q "enabled"; then
        setsebool -P httpd_unified 1
        setsebool -P httpd_can_network_connect 1
        chcon -R -t httpd_sys_content_t /var/www/html/
        chcon -R -t httpd_sys_rw_content_t /var/www/html/conf.d/
        chcon -R -t httpd_sys_rw_content_t /var/www/html/panel/hosts/
        semanage fcontext -a -t mail_spool_t "/var/vmail(/.*)? " || true
        restorecon -Rv /var/vmail || true
        fi
        # ========================================
        # 12. Restart all services
        # ========================================
        systemctl restart pdns postfix dovecot httpd php-fpm
        # ========================================
        # 13. Summary
        # ========================================
        echo "=== Installation completed! ==="
        IP=$(hostname -I | awk '{print $1}')
        echo "Panel: http://$IP:2082/"
        echo "phpMyAdmin: http://$IP/phpmyadmin"
        echo "Admin user: admin / Admin@123"
        echo "DNS backend: PowerDNS (MySQL shared: comzpanel / comzpanel_user)"
        echo "SMTP/IMAP: Postfix/Dovecot (virtual users trong DB comzpanel)"
        echo "IMPORTANT: Change passwords immediately!"
        echo "=== OCCPanel - Nong Duc Canh ==="
    