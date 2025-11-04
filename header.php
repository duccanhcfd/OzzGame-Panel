<?php
// ======================
// OCCPanel Header (Optimized Full)
// ======================

// --- Security headers cơ bản ---
header("X-Frame-Options: SAMEORIGIN");              
header("X-XSS-Protection: 1; mode=block");          
header("X-Content-Type-Options: nosniff");          

// --- Session security chỉ khi session chưa active ---
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    // session.cookie_secure = 1 chỉ bật khi đã có SSL
    session_start();
}

$logged_in = !empty($_SESSION['user_id']);

// Xác định tên hiển thị
if (!empty($_SESSION['user'])) {
    $display_name = $_SESSION['user'];          // Admin user
} elseif (!empty($_SESSION['host_user'])) {
    $display_name = $_SESSION['host_user'];     // Host user
} else {
    $display_name = null;
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OCCPanel</title>
<link rel="stylesheet" href="/style.css">
<style>
/* Header chung */
.site-header {
    background: #111b2d;
    padding: 12px 20px;
    color: #e7ecfa;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    position: sticky;
    top: 0;
    z-index: 1000;
}
.site-header .container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap; /* PC: wrap nếu cần */
}
.site-header .brand a {
    color: #3b82f6;
    font-weight: bold;
    font-size: 20px;
    text-decoration: none;
}
.site-header .menu {
    display: flex;
    gap: 16px;
}
.site-header .menu a {
    color: #e7ecfa;
    text-decoration: none;
    padding: 4px 8px;  /* gọn hơn trên PC */
    font-size: 14px;
    border-radius: 6px;
    transition: background 0.3s;
}
.site-header .menu a:hover {
    background: #3b82f6;
    color: #fff;
}

/* Hamburger cho mobile */
.hamburger {
    display: none;
    flex-direction: column;
    gap: 4px;
    cursor: pointer;
}
.hamburger span {
    width: 25px;
    height: 3px;
    background: #e7ecfa;
    border-radius: 2px;
}

/* PC menu wrap */
@media (min-width: 769px) {
    .site-header .menu {
        flex-wrap: wrap;
        gap: 10px;
    }
}

/* Mobile menu */
@media (max-width: 768px) {
    .site-header .menu {
        position: absolute;
        top: 60px;
        right: 0;
        background: #111b2d;
        flex-direction: column;
        width: 200px;
        padding: 10px;
        display: none;
        box-shadow: -2px 4px 12px rgba(0,0,0,0.5);
        border-radius: 0 0 6px 6px;
        max-height: 70vh;
        overflow-y: auto;
        z-index: 2000;
    }
    .site-header .menu.active {
        display: flex;
    }
    .site-header .menu a {
        padding: 8px;
        font-size: 15px;
    }
    .hamburger {
        display: flex;
    }
}
</style>
</head>
<body>
<header class="site-header">
  <div class="container">
    <div class="brand"><a href="/index.php">OCCPanel</a></div>
    <div class="hamburger" onclick="document.querySelector('.menu').classList.toggle('active')">
      <span></span>
      <span></span>
      <span></span>
    </div>
 <nav class="menu">
<?php
if (!empty($_SESSION['host_user'])) {
    // Menu cho host_user chỉ hiển thị các mục hạn chế
    echo '<a href="/filemanager.php">File manager</a>';
    
    echo '<a href="/login.php">Login</a>';
    echo '<a href="/logout.php">Logout</a>';
} elseif (!empty($_SESSION['user_id'])) {
    // Menu đầy đủ cho admin/user_id
    ?>
    <a href="/createhosting.php">Add hosting</a>
    <a href="/listhost.php">List hosting</a>
<a href="/createvps.php">Create VPS</a>

<a href="/listvps.php">List VPS</a>
    <a href="/filemanager.php">File manager</a>
    
<a href="/vpsapimanager.php">Api VPS</a>

    
    
    
    <a href="/setnameserver.php">Name server</a>
    <a href="/mysql_manager.php">Database MySql</a>
    <a href="/email_manager.php">Email account</a>
    <a href="/ssl_manager.php">SSl manager</a>
    <a href="/api_tokens.php">API key</a>
    <a href="/scanfile.php">Scan file</a>
    <a href="/quota_manager.php">Quota manager</a>
    <a href="/edit_php_ini.php">EditPhp</a>
    <a href="/changer_user.php">User info</a>
    <a href="/dns_manager.php">DNS manager</a>
    <a href="/securety.php">Security</a>
    <a href="/cron.php">Cron job</a>
    <a href="/scansecurity.php">ScanSecurity</a>
    <span style="padding:6px 10px;color:#3b82f6;font-weight:bold;">
        <?= htmlspecialchars($display_name) ?>
    </span>
    <a href="/logout.php">Logout</a>
<?php
} else {
    // Chưa đăng nhập
    echo '<a href="/login.php">Login</a>';
}
?>
</nav>
  </div>
</header>
<main class="container" style="padding:20px;">