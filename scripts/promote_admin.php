<?php
// CLI ONLY: php scripts/promote_admin.php user@example.com
if(PHP_SAPI!=='cli')exit('CLI only');
require dirname(__DIR__).'/app/bootstrap.php';
$email=$argv[1]??'';
if(!filter_var($email,FILTER_VALIDATE_EMAIL))exit("Usage: php scripts/promote_admin.php user@example.com\n");
$r=q('UPDATE users SET is_super_admin=1 WHERE email=?',[$email]);
if(!$r->rowCount())exit("Account not found. Register it first.\n");
echo "Super Admin privilege assigned to existing user.\n";
