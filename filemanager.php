<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

/**
 * COMZPANEL FILE MANAGER – FULL
 * - Root = /panel/hosts (theo hiện trạng)
 * - Danh sách host khi chưa chọn ?host=
 * - Khi vào host => chỉ duyệt bên trong host đó
 * - Breadcrumb: Hosts / Root / ...
 * - Tính năng: Upload, Tạo file/thư mục, Xóa, Sửa, Chmod, Zip/Unzip, Move/Copy (multi-select)
 */

// ================== CONFIG ==================
// Nếu hosts đang nằm TRONG thư mục panel/
$hosts_root = realpath(__DIR__ . '/hosts');
// // Nếu chuyển hosts ra ngang hàng panel, dùng dòng dưới:
// $hosts_root = realpath(__DIR__ . '/../hosts');

if ($hosts_root === false) {
    die("<h2 style='color:red'>❌ Không tìm thấy thư mục hosts</h2>");
}
// ================== INPUTS & PHÂN QUYỀN HOST ==================
$isHostUser = false;
$host = '';

// đảm bảo session đang chạy (auth.php thường đã start session)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Nếu là host user (đã login bằng tài khoản host), ưu tiên session
if (!empty($_SESSION['host_user']) && !empty($_SESSION['host_domain'])) {
    $isHostUser = true;
    // khóa host bằng domain đã gán trong session (user không thể override bằng GET)
    $host = $_SESSION['host_domain'];
} else {
    // admin hoặc chưa login host: lấy host từ querystring (nếu có)
    $host = $_GET['host'] ?? '';
    $host = preg_replace('/[^a-zA-Z0-9_\-\.]/','', $host);
}

// Nếu chưa có host và không phải host_user -> show danh sách hosts
if (!$host && !$isHostUser) {
    $hosts = array_values(array_filter(scandir($hosts_root), function($h) use ($hosts_root){
        return $h[0] !== '.' && is_dir($hosts_root.'/'.$h);
    }));
    ?>
    <section>
      <div class="card" style="max-width: 900px; margin: 0 auto;">
        <h1><i class="fas fa-server"></i> Danh sách Hosting</h1>
        <?php if (empty($hosts)): ?>
          <div class="alert error">Chưa có hosting nào trong <code><?php echo htmlspecialchars($hosts_root); ?></code></div>
        <?php else: ?>
          <ul style="line-height:1.9">
            <?php foreach ($hosts as $h): ?>
              <li>
                <i class="fas fa-folder"></i>
                <a style="color:#4ade80" href="?host=<?php echo urlencode($h); ?>"><?php echo htmlspecialchars($h); ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}

// ================== ROOT HOST ==================
$root = realpath($hosts_root.'/'.$host);
if (!$root || strpos($root, $hosts_root) !== 0) {
    die("<h2 style='color:red'>❌ Host không hợp lệ</h2>");
}

// ================== DIR HIỆN TẠI ==================
$dir = isset($_GET['dir']) ? realpath($root.'/'.$_GET['dir']) : $root;
if ($dir === false || strpos($dir, $root) !== 0) $dir = $root;

$writable = is_writable($dir);



// ================== QUOTA ==================
$quotaFile = "$root/.quota";  // file .quota ẩn trong host
$disk_quota = 1024;           // mặc định MB
$alert_percent = 80;

if (file_exists($quotaFile)) {
    $lines = file($quotaFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/disk_quota=(\d+)/', $line, $m)) $disk_quota = intval($m[1]);
        if (preg_match('/alert_percent=(\d+)/', $line, $m)) $alert_percent = intval($m[1]);
    }
}

function getFolderSizeMB($dir){
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $size += $f->getSize();
    }
    return round($size / 1024 / 1024, 2);
}

$used = getFolderSizeMB($dir);
$percentUsed = round($used / $disk_quota * 100, 2);
$alertClass = ($percentUsed >= $alert_percent) ? 'color:red;font-weight:bold;' : '';

// Hiển thị quota trên đầu File Manager
echo "<div style='margin-bottom:10px; padding:10px; background:#f0f0f0; border-radius:6px;'>
        <strong>Quota:</strong> $used MB / $disk_quota MB ({$percentUsed}%)
        <span style='$alertClass'>".($percentUsed >= $alert_percent ? '⚠️ Gần đầy!' : '')."</span>
      </div>";





// ================== HÀM PHỤ ==================
function bytes_fmt($b){
    $u=['B','KB','MB','GB','TB']; $i=0;
    while($b>=1024&&$i<count($u)-1){$b/=1024;$i++;}
    return sprintf('%.2f %s',$b,$u[$i]);
}
function rmdir_recursive($dir){
    foreach(scandir($dir) as $f){
        if($f=='.'||$f=='..') continue;
        $p="$dir/$f";
        if (is_dir($p)) rmdir_recursive($p); else @unlink($p);
    }
    @rmdir($dir);
}
function copy_recursive($src, $dst){
    if(is_dir($src)){
        @mkdir($dst, 0777, true);
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach($it as $file){
            $destPath = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
            if($file->isDir()){
                @mkdir($destPath, 0777, true);
            } else {
                @copy($file, $destPath);
            }
        }
    } else {
        @copy($src, $dst);
    }
}
function breadcrumbs($dir,$root,$host){
    $rel = str_replace($root,'',$dir);
    $parts = array_filter(explode('/',$rel));

    $html  = '<nav class="breadcrumb">';
    $html .= '<a href="?"><i class="fas fa-server"></i> Hosts</a>';
    $html .= ' / <a href="?host='.urlencode($host).'"><i class="fas fa-home"></i> Root</a>';

    $path='';
    foreach($parts as $p){
        $path.='/'.$p;
        $html.=' / <a href="?host='.urlencode($host).'&dir='.urlencode(ltrim($path,'/')).'">'.htmlspecialchars($p).'</a>';
    }
    return $html.'</nav>';
}

// ================== XỬ LÝ POST ==================
$upload_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect_after = true;

    // --- Upload files ---
    if(isset($_FILES['upload_files'])){
        $redirect_after = false;
        $uploadSuccess = [];
        $uploadErrors = [];

        foreach($_FILES['upload_files']['name'] as $k=>$name){
            $tmp  = $_FILES['upload_files']['tmp_name'][$k];
            $err  = $_FILES['upload_files']['error'][$k];
            $size = $_FILES['upload_files']['size'][$k];

            if($name && $err === UPLOAD_ERR_OK && is_uploaded_file($tmp)){
                if($size > 100 * 1024 * 1024) {
                    $uploadErrors[] = "File '$name' quá lớn (tối đa 100MB)";
                    continue;
                }
                // Không ghi đè
                $originalName = $name;
                $counter = 1;
                $pi = pathinfo($originalName);
                $base = $pi['filename'] ?? $originalName;
                $ext  = isset($pi['extension']) && $pi['extension'] !== '' ? ('.'.$pi['extension']) : '';
                $candidate = $base.$ext;
                while(file_exists($dir.'/'.$candidate)) {
                    $candidate = $base . '_' . $counter . $ext;
                    $counter++;
                }
                if(move_uploaded_file($tmp, $dir.'/'.$candidate)){
                    $uploadSuccess[] = $candidate;
                } else {
                    $uploadErrors[] = "Không thể lưu file: $originalName.";
                }
            } else {
                // xử lý lỗi chung
                $uploadErrors[] = "Upload thất bại: ".htmlspecialchars($name ?: 'unknown');
            }
        }
        foreach($uploadSuccess as $file) {
            $upload_message .= "<div class='alert success'><i class='fas fa-check-circle'></i> Upload thành công: ".htmlspecialchars($file)."</div>";
        }
        foreach($uploadErrors as $error) {
            $upload_message .= "<div class='alert error'><i class='fas fa-exclamation-circle'></i> $error</div>";
        }
    }

    // --- Multi actions ---
    $action = $_POST['action'] ?? '';
    $targets = $_POST['targets'] ?? [];

    // Chuẩn hoá targets thành path thật, và KHÓA trong $root
    $targets = array_filter(array_map(function($t) use($dir,$root){
        $p = realpath($dir.'/'.$t);
        return ($p && strpos($p,$root)===0) ? $p : false;
    }, $targets));

    if ($action && !isset($_FILES['upload_files'])) {
        switch ($action) {
            case 'create_file': {
                $filename = trim($_POST['name'] ?? 'newfile.txt');
                if ($filename !== '') {
                    $dest = $dir.'/'.$filename;
                    // chống vượt root
                    $rp = realpath(dirname($dest));
                    if ($rp && strpos($rp,$root)===0) {
                        @file_put_contents($dest,'');
                    }
                }
                break;
            }
            case 'create_dir': {
                $dirname = trim($_POST['name'] ?? 'newdir');
                if ($dirname !== '') {
                    $dest = $dir.'/'.$dirname;
                    $rp = realpath($dir);
                    if ($rp && strpos($rp,$root)===0) {
                        @mkdir($dest, 0777, true);
                    }
                }
                break;
            }
            case 'delete': {
                foreach($targets as $t){
                    if (is_dir($t)) rmdir_recursive($t);
                    else @unlink($t);
                }
                break;
            }
            case 'chmod': {
                $mode = octdec($_POST['mode'] ?? '0644');
                foreach($targets as $t){ @chmod($t, $mode); }
                break;
            }
            case 'move': {
                // dest: tên mới hoặc thư mục đích (tương đối theo $dir)
                $dest = trim($_POST['dest'] ?? '');
                if ($dest !== '') {
                    foreach($targets as $t) {
                        $base = basename($t);
                        $to = $dir . '/' . rtrim($dest,'/');
                        // nếu dest là thư mục tồn tại => move vào thư mục đó
                        if (is_dir($to)) {
                            $final = $to . '/' . $base;
                        } else {
                            // xem như rename trong current dir
                            $final = $dir . '/' . $dest;
                        }
                        // chống vượt root
                        $parent = realpath(dirname($final));
                        if ($parent && strpos($parent,$root)===0) {
                            @rename($t, $final);
                        }
                    }
                }
                break;
            }
            case 'copy': {
                $dest = trim($_POST['dest'] ?? '');
                if ($dest !== '') {
                    $to = $dir . '/' . rtrim($dest,'/');
                    foreach($targets as $t){
                        if (is_dir($to)) {
                            $final = $to . '/' . basename($t);
                        } else {
                            $final = $to; // copy thành tên chỉ định
                        }
                        $parent = realpath(dirname($final));
                        if (!$parent || strpos($parent,$root)!==0) continue;
                        if (is_dir($t)) copy_recursive($t, $final);
                        else @copy($t, $final);
                    }
                }
                break;
            }
            case 'zip': {
                if (!class_exists('ZipArchive')) break;
                $zip = new ZipArchive();
                $zipFile = $dir.'/archive_'.time().'.zip';
                if($zip->open($zipFile, ZipArchive::CREATE)===true){
                    foreach($targets as $t){
                        if(is_dir($t)){
                            $it = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($t, FilesystemIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::SELF_FIRST
                            );
                            foreach($it as $f){
                                if($f->isDir()) continue;
                                $local = substr($f->getPathname(), strlen($t)+1);
                                $zip->addFile($f->getPathname(), basename($t).'/'.$local);
                            }
                        } else {
                            $zip->addFile($t, basename($t));
                        }
                    }
                    $zip->close();
                }
                break;
            }
            case 'unzip': {
                if (!class_exists('ZipArchive')) break;
                foreach($targets as $t) if(is_file($t)){
                    $zip = new ZipArchive();
                    if ($zip->open($t) === TRUE) {
                        $zip->extractTo($dir);
                        $zip->close();
                    }
                }
                break;
            }
            case 'save_file': {
                // lưu nội dung file (single target)
                foreach($targets as $t) {
                    if (is_file($t) && strpos($t,$root)===0) {
                        @file_put_contents($t, $_POST['content'] ?? '');
                    }
                }
                break;
            }
        }
    }

    // Redirect sau các action (trừ upload để hiển thị message)
    if ($redirect_after && !isset($_FILES['upload_files'])) {
        header("Location: ?host=".urlencode($host)."&dir=".urlencode(str_replace($root.'/', '', $dir)));
        exit;
    }
}

// ================== LIỆT KÊ FILE ==================
$files = array_filter(array_diff(scandir($dir), ['.','..']), function($f){
    return $f[0] !== '.'; // bỏ tất cả file ẩn (ví dụ .quota)
});

// loại bỏ các entry ảo nếu cần
usort($files, function($a,$b) use ($dir){
    $pa = $dir.'/'.$a; $pb = $dir.'/'.$b;
    if (is_dir($pa) && !is_dir($pb)) return -1;
    if (!is_dir($pa) && is_dir($pb)) return 1;
    return strcasecmp($a,$b);
});
?>
<section>
  <h1><i class="fas fa-folder"></i> File Manager: <?php echo htmlspecialchars($host).' '.(str_replace($root,'',$dir)?:'/'); ?></h1>
  <?php echo breadcrumbs($dir,$root,$host); ?>

  <?php if (!empty($upload_message)) echo $upload_message; ?>

  <!-- Upload -->
  <div class="card">
    <h3><i class="fas fa-upload"></i> Upload Files</h3>
    <form method="post" enctype="multipart/form-data" id="uploadForm">
      <div id="dropZone">
        <p><i class="fas fa-cloud-upload-alt"></i> Kéo thả file vào đây hoặc click để chọn</p>
        <input type="file" name="upload_files[]" multiple id="fileInput">
        <div id="fileList"></div>
      </div>
      <button type="submit" class="btn-primary"><i class="fas fa-upload"></i> Upload</button>
    </form>
    <?php if (!$writable): ?>
      <div class="alert error"><i class="fas fa-exclamation-triangle"></i> Thư mục hiện tại không có quyền ghi.</div>
    <?php endif; ?>
  </div>

  <!-- Tạo file / thư mục -->
  <div class="card">
    <h3><i class="fas fa-plus-circle"></i> Tạo mới</h3>
    <form method="post" class="inline-form">
      <input type="hidden" name="action" value="create_file">
      <input type="text" name="name" placeholder="File name" required>
      <button type="submit" class="btn-secondary"><i class="fas fa-file"></i> Tạo File</button>
    </form>
    <form method="post" class="inline-form">
      <input type="hidden" name="action" value="create_dir">
      <input type="text" name="name" placeholder="Folder name" required>
      <button type="submit" class="btn-secondary"><i class="fas fa-folder"></i> Tạo Thư mục</button>
    </form>
  </div>

  <!-- Multi-select actions -->
  <form method="post" id="multiForm">
    <div class="card">
      <h3><i class="fas fa-tasks"></i> Hành động với nhiều file</h3>
      <div class="action-buttons">
        <button type="submit" name="action" value="delete" class="btn-danger" onclick="return confirm('Xác nhận xóa?')"><i class="fas fa-trash"></i> Xóa</button>

        <button type="submit" name="action" value="chmod" class="btn-secondary"><i class="fas fa-key"></i> Chmod</button>
        <input type="text" name="mode" placeholder="0644" pattern="[0-7]{4}" style="width:90px">

        <button type="submit" name="action" value="zip" class="btn-primary"><i class="fas fa-file-archive"></i> Nén ZIP</button>
        <button type="submit" name="action" value="unzip" class="btn-primary"><i class="fas fa-expand-arrows-alt"></i> Giải nén</button>

        <input type="text" name="dest" placeholder="Đích (thư mục hoặc tên mới)" style="min-width:240px">
        <button type="submit" name="action" value="move" class="btn-secondary"><i class="fas fa-arrows-alt"></i> Move</button>
        <button type="submit" name="action" value="copy" class="btn-secondary"><i class="fas fa-copy"></i> Copy</button>
      </div>
    </div>

    <table class="fm-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>Name</th><th>Size</th><th>Type</th><th>Perms</th><th>Modified</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($files as $f):
            $path=$dir.'/'.$f;
            $isDir=is_dir($path);
            $fileExt = $isDir ? '' : strtolower(pathinfo($path, PATHINFO_EXTENSION));
      ?>
        <tr>
          <td><input type="checkbox" name="targets[]" value="<?php echo htmlspecialchars($f); ?>"></td>
          <td>
            <?php if($isDir): ?>
              <i class="fas fa-folder"></i>
              <a href="?host=<?php echo urlencode($host); ?>&dir=<?php echo urlencode(str_replace($root.'/', '', $path)); ?>"><?php echo htmlspecialchars($f); ?></a>
            <?php else: ?>
              <i class="file-icon fas <?php 
                  if (in_array($fileExt, ['zip','rar','7z'])) echo 'fa-file-archive';
                  elseif (in_array($fileExt, ['php','html','js','css'])) echo 'fa-file-code';
                  elseif (in_array($fileExt, ['jpg','jpeg','png','gif','svg','webp','ico'])) echo 'fa-file-image';
                  elseif ($fileExt === 'pdf') echo 'fa-file-pdf';
                  else echo 'fa-file';
              ?>"></i>
              <?php echo htmlspecialchars($f); ?>
            <?php endif; ?>
          </td>
          <td><?php echo $isDir ? '-' : bytes_fmt(@filesize($path)); ?></td>
          <td><?php echo $isDir ? 'Dir' : 'File'; ?></td>
          <td><?php echo substr(sprintf('%o', @fileperms($path)), -4); ?></td>
          <td><?php echo date('Y-m-d H:i', @filemtime($path)); ?></td>
          <td>
            <?php if(!$isDir): ?>
              <a href="?host=<?php echo urlencode($host); ?>&dir=<?php echo urlencode(str_replace($root.'/', '', $dir)); ?>&view=<?php echo urlencode($f); ?>"><i class="fas fa-edit"></i> Sửa</a>
              <?php
                // Link download an toàn (tương đối từ /panel/hosts/<host>/...)
                $downloadRel = str_replace($hosts_root,'/hosts',$path); // để tải theo alias /hosts
              ?>
              <a href="<?php echo htmlspecialchars($downloadRel); ?>" download><i class="fas fa-download"></i> Tải xuống</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </form>

  <?php
  // ========== EDITOR ==========
  if(isset($_GET['view'])):
      $viewFile = realpath($dir.'/'.$_GET['view']);
      if($viewFile && strpos($viewFile,$root)===0 && is_file($viewFile)):
          $content = @file_get_contents($viewFile);
  ?>
  <div class="card">
    <h3><i class="fas fa-edit"></i> Chỉnh sửa: <?php echo htmlspecialchars($_GET['view']); ?></h3>
    <form method="post">
      <input type="hidden" name="action" value="save_file">
      <input type="hidden" name="targets[]" value="<?php echo htmlspecialchars($_GET['view']); ?>">
      <textarea name="content" rows="38" style="width:500px; font-family: monospace;"><?php echo htmlspecialchars($content); ?></textarea>
      <div style="margin-top: 10px;">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Lưu thay đổi</button>
        <a href="?host=<?php echo urlencode($host); ?>&dir=<?php echo urlencode(str_replace($root.'/', '', $dir)); ?>" class="btn-secondary"><i class="fas fa-times"></i> Hủy</a>
      </div>
    </form>
  </div>
  <?php endif; endif; ?>

<style>
.card{background:#111B2D;border-radius:8px;padding:20px;margin-bottom:20px;box-shadow:0 2px 10px rgba(0,0,0,0.1);color:#fff}
.card h3{margin-top:0;border-bottom:1px solid #334155;padding-bottom:10px}
.alert{padding:10px 15px;border-radius:5px;margin-bottom:15px;display:flex;align-items:center}
.alert.success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert.error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
.btn-primary,.btn-secondary,.btn-danger{padding:8px 16px;border:none;border-radius:4px;cursor:pointer;font-size:14px;margin:2px;display:inline-flex;align-items:center}
.btn-primary{background:#3498db;color:#fff}
.btn-secondary{background:#95a5a6;color:#fff}
.btn-danger{background:#e74c3c;color:#fff}
.btn-primary:hover{background:#2980b9}
.btn-secondary:hover{background:#64748b}
.btn-danger:hover{background:#c0392b}
.inline-form{display:inline-block;margin-right:10px}
#dropZone{border:2px dashed #3498db;border-radius:8px;padding:25px;text-align:center;margin-bottom:15px;background:#7d7d7d;transition:all .3s;cursor:pointer}
#dropZone.dragover{border-color:#2ecc71;background-color:#e8f5e9}
#dropZone p{margin:0 0 15px 0;color:#fff;font-size:16px}
#dropZone i{font-size:40px;color:#3498db;margin-bottom:10px}
#fileInput{display:none}
#fileList{text-align:left;margin-top:10px}
#fileList ul{list-style:none;padding:0}
#fileList li{padding:5px;border-bottom:1px solid #eee;display:flex;align-items:center}
#fileList li i{margin-right:8px;color:#fff}
.fm-table{width:100%;border-collapse:collapse;margin-top:15px}
.fm-table th,.fm-table td{padding:10px;text-align:left;border-bottom:1px solid #334155;color:#e2e8f0}
.fm-table th{background:#1f2937;font-weight:600}
.fm-table tr:hover{background:#0f172a}
.file-icon{margin-right:8px;color:#93c5fd}
.action-buttons{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.breadcrumb{margin-bottom:20px;font-size:14px;display:flex;align-items:center;gap:6px}
.breadcrumb a{color:#93c5fd;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.breadcrumb a:hover{text-decoration:underline}
</style>

<script>
// Drag & drop upload
const dropZone=document.getElementById('dropZone');
const fileInput=document.getElementById('fileInput');
const fileList=document.getElementById('fileList');

dropZone.addEventListener('dragover',e=>{e.preventDefault();dropZone.classList.add('dragover');});
dropZone.addEventListener('dragleave',e=>{e.preventDefault();dropZone.classList.remove('dragover');});
dropZone.addEventListener('drop',e=>{
  e.preventDefault();dropZone.classList.remove('dragover');
  if (e.dataTransfer.files.length>0){ fileInput.files=e.dataTransfer.files; updateFileList(); }
});
dropZone.addEventListener('click',()=>fileInput.click());
fileInput.addEventListener('change',updateFileList);

function updateFileList(){
  fileList.innerHTML='';
  if (fileInput.files.length>0){
    const ul=document.createElement('ul');
    for (let i=0;i<fileInput.files.length;i++){
      const f=fileInput.files[i];
      const li=document.createElement('li');
      li.innerHTML='<i class="fas fa-file"></i> '+f.name+' ('+formatFileSize(f.size)+')';
      ul.appendChild(li);
    }
    fileList.appendChild(ul);
  }
}
function formatFileSize(bytes){
  if(bytes===0) return '0 Bytes';
  const k=1024,sizes=['Bytes','KB','MB','GB','TB'];
  const i=Math.floor(Math.log(bytes)/Math.log(k));
  return parseFloat((bytes/Math.pow(k,i)).toFixed(2))+' '+sizes[i];
}

// Select all
const selectAll=document.getElementById('selectAll');
if (selectAll){
  selectAll.addEventListener('change',function(){
    document.querySelectorAll('input[name="targets[]"]').forEach(cb=>cb.checked=this.checked);
  });
}
</script>

<?php include __DIR__ . '/footer.php'; ?>