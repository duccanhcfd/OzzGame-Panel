<?php
require_once __DIR__ . '/auth.php';
require_login();
include __DIR__ . '/header.php';

// ================== CONFIG ==================
$hosts_root = realpath(__DIR__ . '/hosts');
if ($hosts_root === false) die("<h2 style='color:red'>Không tìm thấy thư mục hosts</h2>");

$danger_exts = ['php','exe','js','sh','py','pl'];
$danger_keywords = ['facebook','bank','wallet','pass','key'];

$auto_delete = $_POST['auto_delete'] ?? [];

function bytes_fmt($b){$u=['B','KB','MB','GB','TB'];$i=0;while($b>=1024&&$i<count($u)-1){$b/=1024;$i++;}return sprintf('%.2f %s',$b,$u[$i]);}

function rmdir_recursive($dir){foreach(scandir($dir) as $f){if($f=='.'||$f=='..') continue;$p="$dir/$f";if(is_dir($p)) rmdir_recursive($p); else @unlink($p);}@rmdir($dir);}

// ================== HANDLE POST ==================
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';
    $targets = $_POST['targets'] ?? [];
    foreach($targets as $t){
        $t = realpath($t);
        if(!$t || strpos($t,$hosts_root)!==0) continue;
        if($action==='delete' || in_array($t,$auto_delete)){
            if(is_dir($t)) rmdir_recursive($t); else @unlink($t);
        }
        if($action==='zip'){
            $zip = new ZipArchive();
            $zipFile = $t.'_'.time().'.zip';
            if($zip->open($zipFile, ZipArchive::CREATE)===true){
                if(is_file($t)) $zip->addFile($t,basename($t));
                elseif(is_dir($t)){
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t,FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                    foreach($it as $f) if($f->isFile()) $zip->addFile($f->getPathname(), substr($f->getPathname(),strlen($t)+1));
                }
                $zip->close();
            }
        }
    }
}

// ================== SCAN FILE ==================
$all_files = [];
foreach(array_filter(scandir($hosts_root), fn($h)=>$h[0]!='.' && is_dir($hosts_root.'/'.$h)) as $host){
    $root = $hosts_root.'/'.$host;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if($f->isFile()){
            $filePath = $f->getPathname();
            $ext = strtolower(pathinfo($filePath,PATHINFO_EXTENSION));
            $name = strtolower($f->getFilename());
            $score = 0;
            if(in_array($ext,$danger_exts)) $score+=1000;
            foreach($danger_keywords as $kw) if(strpos($name,$kw)!==false) $score+=500;
            $all_files[]=[
                'host'=>$host,
                'path'=>$filePath,
                'dir'=>$f->getPath(),
                'name'=>$f->getFilename(),
                'ext'=>$ext,
                'size'=>$f->getSize(),
                'mtime'=>$f->getMTime(),
                'score'=>$score
            ];
        }
    }
}

// sắp xếp: ưu tiên score, rồi size
usort($all_files,function($a,$b){return $b['score']<=>$a['score'] ?: $b['size']<=>$a['size'];});

?><section>
  <h1>ComZPanel File Scanner</h1>
  <form method="post">
  <div style="max-height:500px;overflow-y:auto">
  <table class="fm-table">
    <thead>
      <tr><th><input type="checkbox" id="selectAll"></th><th>Host</th><th>Dir</th><th>File</th><th>Size</th><th>Modified</th><th>Actions</th><th>Auto Delete</th></tr>
    </thead>
    <tbody>
    <?php foreach($all_files as $f): ?>
      <tr>
        <td><input type="checkbox" name="targets[]" value="<?php echo htmlspecialchars($f['path']); ?>"></td>
        <td><?php echo htmlspecialchars($f['host']); ?></td>
        <td><?php echo htmlspecialchars(str_replace($hosts_root.'/'.$f['host'],'',$f['dir'])); ?></td>
        <td><?php echo htmlspecialchars($f['name']); ?></td>
        <td><?php echo bytes_fmt($f['size']); ?></td>
        <td><?php echo date('Y-m-d H:i',$f['mtime']); ?></td>
        <td>
          <a href="<?php echo htmlspecialchars(str_replace($hosts_root,'/hosts',$f['path'])); ?>" download>Download</a> |
          <button type="submit" name="action" value="delete">Delete</button> |
          <button type="submit" name="action" value="zip">Zip</button>
        </td>
        <td><input type="checkbox" name="auto_delete[]" value="<?php echo htmlspecialchars($f['path']); ?>" <?php echo ($f['score']>0?'checked':''); ?>></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </form>
</section>
<script>
const selectAll=document.getElementById('selectAll');
if(selectAll) selectAll.addEventListener('change',()=>{
  document.querySelectorAll('input[name^="targets"]').forEach(cb=>cb.checked=selectAll.checked);
});
</script>
<?php include __DIR__ . '/footer.php'; ?>