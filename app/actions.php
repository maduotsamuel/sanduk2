<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
function post_action():void {
    global $config;
    if ($_SERVER['REQUEST_METHOD']!=='POST')return;
    check_csrf();
    $act=value('action',40);
    if ($act==='register') {
        throttle('signup',$_SERVER['REMOTE_ADDR']??'unknown',5,60);
        $name=required('full_name',120);$email=strtolower(required('email',190));$pass=(string)($_POST['password']??'');
        if(!hash_equals($pass,(string)($_POST['confirm_password']??'')))throw new RuntimeException('Passwords do not match.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid email address.');
        if(mb_strlen($name)<2 || strlen($pass)<12 || strlen($pass)>256)throw new RuntimeException('Name must have 2 characters and password must have at least 12.');
        $n=(int)q('SELECT COUNT(*) FROM users WHERE email=?',[$email])->fetchColumn();if($n)throw new RuntimeException('Email already in use. Sign in instead.');
        q('INSERT INTO users(full_name,email,password_hash) VALUES(?,?,?)',[$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
        session_regenerate_id(true);$_SESSION['uid']=(int)db()->lastInsertId();$_SESSION['born']=time();$_SESSION['last_activity']=time();flash('Account created. You can now create or join a group.');go('?page=groups');
    }
    if ($act==='login') {
        $email=strtolower(value('email',190));$pass=(string)($_POST['password']??'');
        $secret=(string)($config['app_key']??'');
        $idHash=hash_hmac('sha256',$email,$secret);$ipHash=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',$secret);
        $cnt=(int)q('SELECT COUNT(*) FROM login_attempts WHERE (identifier_hash=? OR ip_hash=?) AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[$idHash,$ipHash])->fetchColumn();
        if($cnt>=10)throw new RuntimeException('Too many login attempts. Try again later.');
        $user=q('SELECT * FROM users WHERE email=?',[$email])->fetch();
        if(!$user||!password_verify($pass,$user['password_hash'])||$user['is_suspended']) {
            q('INSERT INTO login_attempts(identifier_hash,ip_hash) VALUES (?,?)',[$idHash,$ipHash]);
            throw new RuntimeException('Invalid login details or account unavailable.');
        }
        q('DELETE FROM login_attempts WHERE identifier_hash=?',[$idHash]);
        session_regenerate_id(true);$_SESSION['uid']=(int)$user['id'];$_SESSION['born']=time();$_SESSION['last_activity']=time();flash('Welcome back.');go('?page=groups');
    }
    if($act==='logout') {$_SESSION=[];session_regenerate_id(true);flash('Signed out.');go('?');}
    $u=require_user();$uid=(int)$u['id'];
    if($act==='profile') {
        $name=required('full_name',120);if(mb_strlen($name)<2)throw new RuntimeException('Invalid name.');
        q('UPDATE users SET full_name=? WHERE id=?',[$name,$uid]);flash('Profile updated.');go('?page=profile');
    }
    if($act==='change_password') {
        $old=(string)($_POST['old_password']??'');$new=(string)($_POST['new_password']??'');
        if(!hash_equals($new,(string)($_POST['confirm_new_password']??'')))throw new RuntimeException('New passwords do not match.');
        $hash=q('SELECT password_hash FROM users WHERE id=?',[$uid])->fetchColumn();
        if(!password_verify($old,$hash)||strlen($new)<12||strlen($new)>256)throw new RuntimeException('Wrong current password or new password is shorter than 12 characters.');
        q('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$uid]);
        session_regenerate_id(true);flash('Password changed.');go('?page=profile');
    }
    if($act==='create_group') {
        throttle('create-group',(string)$uid,6,60);
        $name=required('name',120);$amount=positive_amount('amount');$frequency=value('frequency',10);$rate=value('interest_rate',10);
        if(!in_array($frequency,['weekly','monthly'],true)||!preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/',$rate))throw new RuntimeException('Invalid group rules.');
        for($i=0;$i<4;$i++) {
            $code=strtoupper(bin2hex(random_bytes(6)));
            try {
                db()->beginTransaction();
                q('INSERT INTO `groups` (name,description,join_code,contribution_amount,frequency,interest_rate,chairperson_id) VALUES(?,?,?,?,?,?,?)',[$name,value('description'),$code,$amount,$frequency,$rate,$uid]);
                $gid=(int)db()->lastInsertId();q('INSERT INTO memberships(group_id,user_id,status,decided_at) VALUES (?,?,"active",NOW())',[$gid,$uid]);
                log_action($uid,$gid,'create_group','group',$gid);db()->commit();flash('Savings group created.');go('?group='.$gid);
            } catch(PDOException $e){if(db()->inTransaction())db()->rollBack();if($e->getCode()!=='23000')throw $e;}
        }
        throw new RuntimeException('Could not create an invitation code.');
    }
    if($act==='join_group') {
        throttle('join-group',(string)$uid,20,60);
        $code=strtoupper(required('join_code',12));
        $g=q('SELECT id FROM `groups` WHERE join_code=? AND status="active"',[$code])->fetch();if(!$g)throw new RuntimeException('Invitation code not found.');
        q('INSERT INTO memberships(group_id,user_id,status) VALUES (?,?,"pending") ON DUPLICATE KEY UPDATE status=IF(status="rejected","pending",status),requested_at=IF(status="rejected",NOW(),requested_at)',[$g['id'],$uid]);
        flash('Membership request sent to the Chairperson.');go('?page=groups');
    }
    if($act==='ticket') {
        throttle('support',(string)$uid,8,60);
        q('INSERT INTO support_tickets(user_id,subject,message) VALUES(?,?,?)',[$uid,required('subject',150),required('message',2000)]);
        flash('Your support request was sent.');go('?page=support');
    }
    if($act==='admin_ticket'||$act==='admin_group') {
        if(!$u['is_super_admin'])throw new RuntimeException('Admin access required.');
        $id=(int)value('id');if($id<=0)throw new RuntimeException('Invalid record.');
        if($act==='admin_ticket') {
            q('UPDATE support_tickets SET status="resolved",resolved_at=NOW() WHERE id=? AND status="open"',[$id]);log_action($uid,null,'resolve_ticket','ticket',$id);
        }else{
            $status=value('status');if(!in_array($status,['active','paused'],true))throw new RuntimeException('Invalid status.');
            q('UPDATE `groups` SET status=? WHERE id=?',[$status,$id]);log_action($uid,$id,'set_group_'.$status,'group',$id);
        }
        flash('Platform record updated.');go('?page=admin');
    }
    $gid=(int)value('group_id');if($gid<1)throw new RuntimeException('Select a group first.');
    $g=group_access($gid,$uid);active_group($g);
    if($act==='review_member') {
        if(!is_chair($g,$uid))throw new RuntimeException('Only the Chairperson approves members.');
        $target=(int)value('member_id');$decision=value('decision');if(!in_array($decision,['active','rejected'],true))throw new RuntimeException('Invalid action.');
        db()->beginTransaction();try {
            $m=q('SELECT status FROM memberships WHERE group_id=? AND user_id=? FOR UPDATE',[$gid,$target])->fetch();
            if(!$m||$m['status']!=='pending')throw new RuntimeException('Membership request is no longer pending.');
            q('UPDATE memberships SET status=?,decided_at=NOW() WHERE group_id=? AND user_id=?',[$decision,$gid,$target]);
            log_action($uid,$gid,'membership_'.$decision,'member',$target);db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        flash('Membership reviewed.');go('?group='.$gid.'&page=members');
    }
    if($act==='request') {
        throttle('financial-request',(string)$uid,30,60);
        $kind=value('kind',20);
        if(!in_array($kind,['contribution','loan','repayment','withdrawal','expense'],true))throw new RuntimeException('Invalid request type.');
        $amt=positive_amount();$details=required('details',500);
        $period=$kind==='contribution'?required('period_label',80):null;
        $reference=value('reference_no',120);
        $loanId=$kind==='repayment'?(int)value('loan_id'):null;
        $due=$kind==='loan'?required('due_date',10):null;
        $rate=null;$total=null;
        if($kind==='expense'&&!is_chair($g,$uid))throw new RuntimeException('Only the Chairperson may submit group expenses.');
        if($kind==='loan') {
            if(!valid_date((string)$due)||$due<=date('Y-m-d'))throw new RuntimeException('Choose a future due date.');
            $rate=(float)$g['interest_rate'];$total=number_format(round((float)$amt*(1+$rate/100),2),2,'.','');
        }
        if($kind==='repayment'){
            $loan=q('SELECT id FROM requests WHERE id=? AND group_id=? AND member_id=? AND kind="loan" AND status="disbursed"',[$loanId,$gid,$uid])->fetch();
            if(!$loan)throw new RuntimeException('Select your own disbursed loan.');
        }
        q('INSERT INTO requests(group_id,member_id,kind,amount,details,period_label,reference_no,loan_id,due_date,interest_rate,total_due) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$gid,$uid,$kind,$amt,$details,$period,$reference?:null,$loanId,$due,$rate,$total]);
        $rid=(int)db()->lastInsertId();log_action($uid,$gid,'submit_'.$kind,'request',$rid);flash('Request submitted for approval.');go('?group='.$gid.'&page='.$kind.'s');
    }
    if($act==='review_request'||$act==='disburse_loan') {
        if(!is_chair($g,$uid))throw new RuntimeException('Only the Chairperson can approve requests.');
        $rid=(int)value('request_id');if($rid<1)throw new RuntimeException('Invalid request ID.');
        $decision=$act==='disburse_loan'?'disburse':value('decision');
        if(!in_array($decision,['approve','reject','disburse'],true))throw new RuntimeException('Invalid decision.');
        db()->beginTransaction();try{
            // Serialize all financial reviews for the same group to prevent double spending.
            q('SELECT id FROM `groups` WHERE id=? FOR UPDATE',[$gid]);
            $r=q('SELECT * FROM requests WHERE id=? AND group_id=? FOR UPDATE',[$rid,$gid])->fetch();
            if(!$r)throw new RuntimeException('Request not found.');
            $expected=$decision==='disburse'?'approved':'pending';
            if($r['status']!==$expected)throw new RuntimeException('This request has already been reviewed.');
            if($decision==='reject'){
                q('UPDATE requests SET status="rejected",decided_by=?,decided_at=NOW() WHERE id=?',[$uid,$rid]);
            }elseif($r['kind']==='loan'){
                if($decision==='approve'){
                    q('UPDATE requests SET status="approved",decided_by=?,decided_at=NOW() WHERE id=?',[$uid,$rid]);
                }elseif($decision==='disburse'){
                    if(balance($gid)<(float)$r['amount'])throw new RuntimeException('Not enough group cash to disburse loan.');
                    q('UPDATE requests SET status="disbursed",decided_by=?,decided_at=NOW() WHERE id=?',[$uid,$rid]);
                    q('INSERT INTO ledger(group_id,request_id,member_id,entry_type,amount) VALUES (?,?,?,?,?)',[$gid,$rid,$r['member_id'],'loan_disbursement',-1*(float)$r['amount']]);
                }
            }else{
                if($decision==='disburse')throw new RuntimeException('Only loans can be disbursed.');
                if($r['kind']==='repayment'){
                    $loan=q('SELECT * FROM requests WHERE id=? AND group_id=? AND member_id=? AND kind="loan" FOR UPDATE',[$r['loan_id'],$gid,$r['member_id']])->fetch();
                    if(!$loan || $loan['status']!=='disbursed')throw new RuntimeException('Loan is no longer open.');
                    $paid=(float)q('SELECT COALESCE(SUM(amount),0) FROM requests WHERE kind="repayment" AND loan_id=? AND status="approved"',[$loan['id']])->fetchColumn();
                    if((float)$r['amount']>round((float)$loan['total_due']-$paid,2)+0.001)throw new RuntimeException('Repayment exceeds the outstanding loan amount.');
                }
                if($r['kind']==='withdrawal' && (float)$r['amount']>savings($gid,(int)$r['member_id'])+0.001)throw new RuntimeException('Withdrawal exceeds member savings.');
                if(in_array($r['kind'],['withdrawal','expense'],true)&&(float)$r['amount']>balance($gid)+0.001)throw new RuntimeException('Group has insufficient available cash.');
                $entry=['contribution'=>'contribution','repayment'=>'repayment','withdrawal'=>'withdrawal','expense'=>'expense'][$r['kind']];
                $signed=in_array($r['kind'],['withdrawal','expense'],true)?-1*(float)$r['amount']:(float)$r['amount'];
                q('UPDATE requests SET status="approved",decided_by=?,decided_at=NOW() WHERE id=?',[$uid,$rid]);
                q('INSERT INTO ledger(group_id,request_id,member_id,entry_type,amount) VALUES(?,?,?,?,?)',[$gid,$rid,$r['member_id'],$entry,$signed]);
                if($r['kind']==='repayment'){
                    $newPaid=$paid+(float)$r['amount'];
                    if($newPaid+0.001>=(float)$loan['total_due'])q('UPDATE requests SET status="repaid" WHERE id=?',[$loan['id']]);
                }
            }
            log_action($uid,$gid,$decision.'_'.$r['kind'],'request',$rid,$uid===(int)$r['member_id'],'Chairperson action');
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        flash('Decision recorded.');go('?group='.$gid.'&page=approvals');
    }
    throw new RuntimeException('Unsupported action.');
}
