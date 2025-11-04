<?php
// listvps.php

require_once __DIR__ . '/config.php';   // kết nối DB + cấu hình chung
require_once __DIR__ . '/auth.php';     // kiểm tra login nếu hệ thống có
require_once __DIR__ . '/header.php';   // header + style

$vpsList = $pdo->query("SELECT * FROM vps")->fetchAll();

echo "<table class='table table-striped table-bordered'>";
echo "<thead>
<tr>
<th>ID</th>
<th>Name VPS</th>
<th>OS</th>
<th>RAM</th>
<th>CPU</th>
<th>SSH User</th>
<th>SSH Pass</th>
<th>Port SSH</th>
<th>Web URL</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead><tbody>";

foreach($vpsList as $vps){
    echo "<tr>";
    echo "<td>{$vps['id']}</td>";
    echo "<td>{$vps['name']}</td>";
    echo "<td>{$vps['os']}</td>";
    echo "<td>{$vps['ram']}</td>";
    echo "<td>{$vps['cpu']}</td>";
    echo "<td>{$vps['ssh_user']}</td>";
    echo "<td>{$vps['ssh_pass']}</td>";
    echo "<td>{$vps['port_ssh']}</td>";
    echo "<td><a href='{$vps['web_url']}' target='_blank'>{$vps['web_url']}</a></td>";
    echo "<td>{$vps['status']}</td>";
    echo "<td>
        <a href='action.php?cmd=start&id={$vps['id']}'>Start</a> | 
        <a href='action.php?cmd=stop&id={$vps['id']}'>Stop</a> | 
        <a href='action.php?cmd=delete&id={$vps['id']}'>Delete</a> | 
        <a href='action.php?cmd=reinstall&id={$vps['id']}'>Reinstall OS</a>
    </td>";
    echo "</tr>";
}

echo "</tbody></table>";

require_once __DIR__ . '/footer.php';
?>