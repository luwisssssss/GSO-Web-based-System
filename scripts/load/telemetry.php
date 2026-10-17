<?php
require __DIR__.'/common.php';
$p=loadConnection();$out=['timestamp'=>date('c')];
foreach($p->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Innodb_row_lock_current_waits','Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_deadlocks','Aborted_connects')")->fetchAll() as $r)$out[$r['Variable_name']]=(int)$r['Value'];
echo json_encode($out),PHP_EOL;
