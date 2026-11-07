<?php
declare(strict_types=1);
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Setup required: copy app/config.example.php to app/config.php and configure it outside the public folder.');
}
$config = require $configFile;
if (!is_array($config)) {http_response_code(503); exit('Invalid configuration');}
ini_set('display_errors','0');
error_reporting(E_ALL);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: private, no-store, max-age=0');
header('X-Permitted-Cross-Domain-Policies: none');
if (($config['secure_cookies'] ?? false)) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
// Use a session cookie scoped to this application; session state stays server-side.
session_name('SANDUKSID');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(bool)($config['secure_cookies']??true),'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (!isset($_SESSION['born'])) $_SESSION['born'] = time();
if (isset($_SESSION['uid'])) {
    if (time() - (int)($_SESSION['last_activity']??0) > 900 || time() - (int)$_SESSION['born'] > 28800) {
        $_SESSION=[];session_regenerate_id(true);$_SESSION['flash']='Session expired. Please log in again.';
    } else {
        $_SESSION['last_activity']=time();
    }
}
function db(): PDO {
    static $pdo=null;
    global $config;
    if ($pdo===null) {
        $pdo = new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',
            $config['db_user'],$config['db_pass'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
    }
    return $pdo;
}
function q(string $sql,array $args=[]): PDOStatement { $s=db()->prepare($sql);$s->execute($args);return $s; }
function h(mixed $s):string {return htmlspecialchars((string)($s??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function money(mixed $s):string {return 'SSP '.number_format((float)$s,2);}
function csrf():string {return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function check_csrf():void {if(!hash_equals(csrf(),(string)($_POST['csrf']??''))) throw new RuntimeException('Security check failed. Refresh and try again.');}
function flash(string $s):void {$_SESSION['flash']=$s;}
function go(string $to='?'):void {header('Location: '.$to, true,303);exit;}
function current_user():?array {
    if (empty($_SESSION['uid']))return null;
    $u=q('SELECT id,full_name,email,is_super_admin,is_suspended FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetch();
    if (!$u || $u['is_suspended']) {unset($_SESSION['uid']);return null;}
    return $u;
}
function require_user():array {$u=current_user();if(!$u)throw new RuntimeException('Please sign in first.');return $u;}
function group_access(int $gid,int $uid):array {
    $g=q('SELECT g.*,m.status AS membership FROM `groups` g JOIN memberships m ON m.group_id=g.id WHERE g.id=? AND m.user_id=? AND m.status="active"',[$gid,$uid])->fetch();
    if (!$g) throw new RuntimeException('Group not found or access denied.');return $g;
}
function is_chair(array $g,int $uid):bool {return (int)$g['chairperson_id']===$uid;}
function active_group(array $g):void {if($g['status']!=='active')throw new RuntimeException('Group is paused. Contact support.');}
function value(string $key,int $max=500):string {
    $v=$_POST[$key]??'';
    if (!is_string($v)) throw new RuntimeException('Invalid form field: '.$key);
    return trim(mb_substr($v,0,$max));
}
function throttle(string $category,string $identifier,int $limit,int $minutes):void {
    global $config;
    $key=(string)($config['app_key']??'');
    $tag=hash_hmac('sha256','throttle:'.$category.':'.$identifier,$key);
    $ip=hash_hmac('sha256','throttle-ip:'.$category.':'.($_SERVER['REMOTE_ADDR']??'unknown'),$key);
    $minutes=max(1,min(1440,$minutes));
    $count=(int)q('SELECT COUNT(*) FROM login_attempts WHERE identifier_hash=? AND created_at > DATE_SUB(NOW(), INTERVAL '.$minutes.' MINUTE)',[$tag])->fetchColumn();
    if($count >= $limit) throw new RuntimeException('Too many requests. Please try later.');
    q('INSERT INTO login_attempts(identifier_hash,ip_hash) VALUES(?,?)',[$tag,$ip]);
}
function valid_date(string $date):bool {
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    return $dt!==false && $dt->format('Y-m-d')===$date;
}
function required(string $key,int $max=500):string {$v=value($key,$max);if($v==='')throw new RuntimeException("Missing field: $key");return $v;}
function positive_amount(string $name='amount'):string {
    $v=value($name,40);
    if(!preg_match('/^\d{1,15}(?:\.\d{1,2})?$/',$v) || (float)$v<=0)throw new RuntimeException('Enter a valid positive amount.');
    return number_format((float)$v,2,'.','');
}
function log_action(int $actor,?int $group,string $action,string $entity,int $id,bool $self=false,string $note=''):void {
    q('INSERT INTO audit_log(actor_id,group_id,action,entity_type,entity_id,self_approved,note) VALUES (?,?,?,?,?,?,?)',[$actor,$group,$action,$entity,$id,(int)$self,$note]);
}
function balance(int $gid):float {return (float)q('SELECT COALESCE(SUM(amount),0) AS total FROM ledger WHERE group_id=?',[$gid])->fetchColumn();}
function savings(int $gid,int $uid):float {
    return (float)q("SELECT COALESCE(SUM(CASE WHEN entry_type='contribution' THEN amount WHEN entry_type='withdrawal' THEN amount ELSE 0 END),0) FROM ledger WHERE group_id=? AND member_id=?",[$gid,$uid])->fetchColumn();
}
