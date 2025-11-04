<?php
// ssl_manager_all.php - Quản lý SSL cho tất cả domain
require_once __DIR__ . '/auth.php';
require_login();

include __DIR__ . '/header.php';

$ssl_config = "/var/www/html/conf.d/vhost_occ.asia-le-ssl.conf";
$backup_config = "/var/www/html/conf.d/vhost_occ.asia-le-ssl.conf.backup";

if (isset($_POST['action'])) {
    if ($_POST['action'] == 'enable') {
        if (file_exists($backup_config)) {
            rename($backup_config, $ssl_config);
        }
        shell_exec('sudo systemctl restart httpd 2>&1');
        $message = "✅ Đã BẬT SSL cho TẤT CẢ domain!";
    } elseif ($_POST['action'] == 'disable') {
        if (file_exists($ssl_config)) {
            rename($ssl_config, $backup_config);
        }
        shell_exec('sudo systemctl restart httpd 2>&1');
        $message = "✅ Đã TẮT SSL cho TẤT CẢ domain!";
    }
}

$ssl_status = file_exists($ssl_config) ? "ĐANG BẬT 🟢" : "ĐANG TẮT 🔴";
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">🔐 QUẢN LÝ SSL - TẤT CẢ DOMAIN</h3>
                </div>
                <div class="card-body">
                    <div class="alert <?= file_exists($ssl_config) ? 'alert-success' : 'alert-danger' ?> text-center">
                        <h4>Trạng thái: <strong><?= $ssl_status ?></strong></h4>
                        <p class="mb-0">Ảnh hưởng đến: occ.asia, www.occ.asia, cj.occ.asia và tất cả domain khác có SSL</p>
                    </div>

                    <?php if (isset($message)): ?>
                        <div class="alert alert-info text-center"><?= $message ?></div>
                    <?php endif; ?>

                    <div class="text-center mb-4">
                        <form method="post">
                            <button type="submit" name="action" value="enable" class="btn btn-success btn-lg mx-2">
                                <i class="fas fa-lock"></i> 🚀 BẬT SSL (ALL)
                            </button>
                            <button type="submit" name="action" value="disable" class="btn btn-danger btn-lg mx-2">
                                <i class="fas fa-unlock"></i> 🔴 TẮT SSL (ALL)
                            </button>
                        </form>
                    </div>

                    <div class="card bg-light">
                        <div class="card-body">
                            <h5>📖 Hướng dẫn sử dụng:</h5>
                            <ul class="list-unstyled">
                                <li>• <strong>BẬT SSL</strong>: Khi không cần tạo host mới</li>
                                <li>• <strong>TẮT SSL</strong>: Khi cần tạo host mới (tránh lỗi config)</li>
                                <li>• <strong>Lưu ý</strong>: Ảnh hưởng đến TẤT CẢ domain có SSL</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>