<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
 $u=require_user();$gid=(int)($_GET['group']??0);$g=group_access($gid,(int)$u['id']);
 $type=(string)($_GET['type']??'');
 if($type==='ledger')$rows=q('SELECT created_at,entry_type,member_id,amount,request_id FROM ledger WHERE group_id=? ORDER BY id DESC',[$gid])->fetchAll();
 elseif($type==='requests')$rows=q('SELECT created_at,member_id,kind,amount,status,details,period_label,reference_no,loan_id,due_date,total_due,decided_by,decided_at FROM requests WHERE group_id=? ORDER BY id DESC',[$gid])->fetchAll();
 else throw new RuntimeException('Invalid export');
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="sanduk-'.$type.'-'.$gid.'.csv"');
 $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
 if($rows){fputcsv($out,array_keys($rows[0]));foreach($rows as $row){$safe=[];foreach($row as $v){$s=(string)($v??'');if(preg_match('/^\s*[=+@\-\t\r]/u',$s))$s="'".$s;$safe[]=$s;}fputcsv($out,$safe);}}
 fclose($out);
}catch(Throwable $e){http_response_code(403);echo 'Export unavailable.';}
