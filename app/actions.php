<?php
declare(strict_types=1);

function handle_action(): void {
    global $db, $user, $business, $config, $mysql;
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')) || empty($_SESSION['csrf'])) throw new DomainException('Your form expired. Refresh the page and try again.');
    $action = input('action');
    if ($action === 'register') {
        $name = input('name', 150); $company = input('company', 150); $email = email_input(); $password = valid_password();
        $slug = strtolower(input('slug', 60));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) throw new DomainException('Use lowercase letters, numbers and hyphens for your business link.');
        $currency = enum_value('currency', ['THB', 'USD', 'EUR', 'GBP', 'AUD', 'SGD']);
        if (one('SELECT id FROM users WHERE email=?', [$email])) throw new DomainException('This email is already registered.');
        if (one('SELECT id FROM businesses WHERE slug=?', [$slug])) throw new DomainException('This business link is already taken.');
        $db->beginTransaction();
        q('INSERT INTO businesses (name,slug,email,phone,currency,created_at) VALUES (?,?,?,?,?,?)', [$company,$slug,$email,input('phone',60,false),$currency,now()]);
        $biz = (int)$db->lastInsertId();
        q('INSERT INTO users (business_id,name,email,password,role,created_at) VALUES (?,?,?,?,?,?)', [$biz,$name,$email,password_hash($password,PASSWORD_DEFAULT),'owner',now()]);
        $uid = (int)$db->lastInsertId(); $db->commit(); session_regenerate_id(true); $_SESSION['user_id'] = $uid; unset($_SESSION['csrf']); flash('Your workspace is ready. Add your first service to start receiving requests.'); go();
    }
    if ($action === 'login') {
        $email = email_input(); $key = hash('sha256', strtolower($email) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
        $attempt = one('SELECT * FROM login_attempts WHERE attempt_key=?', [$key]);
        if ($attempt && time() - (int)$attempt['started_at'] >= 900) { q('DELETE FROM login_attempts WHERE attempt_key=?', [$key]); $attempt = null; }
        if ($attempt && (int)$attempt['attempts'] >= 8) throw new DomainException('Too many sign-in attempts. Try again in 15 minutes.');
        $found = one('SELECT * FROM users WHERE email=?', [$email]);
        $ok = password_verify((string)($_POST['password'] ?? ''), $found['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$found || !$ok) {
            if ($attempt) q('UPDATE login_attempts SET attempts=attempts+1 WHERE attempt_key=?', [$key]);
            else q('INSERT INTO login_attempts (attempt_key,attempts,started_at) VALUES (?,?,?)', [$key,1,time()]);
            throw new DomainException('Email or password is incorrect.');
        }
        q('DELETE FROM login_attempts WHERE attempt_key=?', [$key]); session_regenerate_id(true); $_SESSION['user_id'] = $found['id']; unset($_SESSION['csrf']); go();
    }
    if ($action === 'request') {
        $biz = one('SELECT * FROM businesses WHERE slug=?', [input('business',60)]);
        if (!$biz) throw new DomainException('Business not found.');
        $service = one('SELECT * FROM services WHERE id=? AND business_id=? AND active=1', [(int)($_POST['service_id'] ?? 0),$biz['id']]);
        if (!$service) throw new DomainException('Select an available service.');
        // Database-backed per-client limit also applies across new sessions.
        $key = hash('sha256', 'request|' . $biz['id'] . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
        $attempt = one('SELECT * FROM login_attempts WHERE attempt_key=?', [$key]);
        if ($attempt && time() - (int)$attempt['started_at'] < 3600 && (int)$attempt['attempts'] >= 10) throw new DomainException('Request limit reached. Please contact the business directly.');
        $name=input('name',150); $email=email_input(); $phone=input('phone',60); $address=input('address',1000); $title=input('title',180); $description=input('description',5000);
        $db->beginTransaction();
        q('INSERT INTO customers (business_id,name,email,phone,address) VALUES (?,?,?,?,?)', [$biz['id'],$name,$email,$phone,$address]); $customer=(int)$db->lastInsertId();
        $token=bin2hex(random_bytes(32));
        q('INSERT INTO tickets (business_id,customer_id,service_id,title,description,address,priority,status,token,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$biz['id'],$customer,$service['id'],$title,$description,$address,'normal','open',$token,now(),now()]);
        activity((int)$db->lastInsertId(),'Request received from customer.',(int)$biz['id']);
        if ($attempt) q('UPDATE login_attempts SET attempts=?,started_at=? WHERE attempt_key=?', [time()-(int)$attempt['started_at']>=3600 ? 1 : (int)$attempt['attempts']+1,time()-(int)$attempt['started_at']>=3600 ? time() : $attempt['started_at'],$key]);
        else q('INSERT INTO login_attempts (attempt_key,attempts,started_at) VALUES (?,?,?)', [$key,1,time()]);
        $db->commit(); flash('Request received. Save this private link to track progress and review quotes.'); go(['page'=>'track','token'=>$token]);
    }
    if ($action === 'quote_response') {
        $token=input('token',64); $ticket=one('SELECT * FROM tickets WHERE token=?',[$token]);
        if (!$ticket) throw new DomainException('Tracking link not found.');
        $response=enum_value('response',['accepted','declined']);
        $db->beginTransaction();
        $s=q("UPDATE documents SET status=? WHERE id=? AND ticket_id=? AND business_id=? AND kind='quote' AND status='sent' AND (due_date='' OR due_date>=?)",[$response,(int)($_POST['document_id']??0),$ticket['id'],$ticket['business_id'],date('Y-m-d')]);
        if (!$s->rowCount()) throw new DomainException('This quote is no longer available for a response.');
        activity((int)$ticket['id'],'Customer '.$response.' quote #'.(int)$_POST['document_id'].'.',(int)$ticket['business_id']); $db->commit(); flash('Quote '.$response.'.'); go(['page'=>'track','token'=>$token]);
    }
    if (!$user) throw new DomainException('Please sign in to continue.');
    $biz=(int)$user['business_id'];
    if ($action==='logout') { $_SESSION=[]; session_regenerate_id(true); go(['page'=>'login']); }
    if ($action==='service_save') {
        owner(); $name=input('name',150); $description=input('description',3000,false); $price=cents(input('price',20)); $id=(int)($_POST['id']??0);
        if ($id) { scoped('services',$id); q('UPDATE services SET name=?,description=?,price=?,active=? WHERE id=? AND business_id=?',[$name,$description,$price,isset($_POST['active'])?1:0,$id,$biz]); }
        else q('INSERT INTO services (business_id,name,description,price,active) VALUES (?,?,?,?,1)',[$biz,$name,$description,$price]);
        flash('Service saved.'); go(['page'=>'services']);
    }
    if ($action==='customer_save') { owner(); q('INSERT INTO customers (business_id,name,email,phone,address) VALUES (?,?,?,?,?)',[$biz,input('name',150),email_input(),input('phone',60,false),input('address',1000,false)]); flash('Customer added.'); go(['page'=>'customers']); }
    if ($action==='ticket_create') {
        owner(); $customer=scoped('customers',(int)($_POST['customer_id']??0)); $service=scoped('services',(int)($_POST['service_id']??0));
        if (!$service['active']) throw new DomainException('Choose an active service.');
        $assigned=(int)($_POST['assigned_to']??0); if ($assigned) scoped('users',$assigned);
        $schedule=schedule_input(); $status=$schedule!==''?'scheduled':'open';
        q('INSERT INTO tickets (business_id,customer_id,service_id,assigned_to,title,description,address,priority,status,scheduled_at,token,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$biz,$customer['id'],$service['id'],$assigned?:null,input('title',180),input('description',5000,false),input('address',1000),enum_value('priority',['low','normal','high','urgent']),$status,$schedule,bin2hex(random_bytes(32)),now(),now()]);
        $id=(int)$db->lastInsertId(); activity($id,'Ticket created by '.$user['name'].'.'); flash('Ticket created.'); go(['page'=>'ticket','id'=>$id]);
    }
    if ($action==='ticket_update') {
        $t=ticket_access((int)($_POST['id']??0)); $status=enum_value('status',['open','scheduled','in_progress','waiting','completed','cancelled']);
        $allowed=['open'=>['open','scheduled','in_progress','cancelled'],'scheduled'=>['scheduled','open','in_progress','cancelled'],'in_progress'=>['in_progress','waiting','completed','cancelled'],'waiting'=>['waiting','in_progress','cancelled'],'completed'=>['completed','in_progress'],'cancelled'=>['cancelled','open']];
        if (!in_array($status,$allowed[$t['status']],true)) throw new DomainException('That status change is not available. Start work before completing a ticket.');
        $assigned=(int)$t['assigned_to']; $schedule=$t['scheduled_at']; $priority=$t['priority'];
        if ($user['role']==='owner') { $assigned=(int)($_POST['assigned_to']??0); if ($assigned) scoped('users',$assigned); $schedule=schedule_input(); $priority=enum_value('priority',['low','normal','high','urgent']); }
        if ($status==='scheduled' && $schedule==='') throw new DomainException('Set an appointment time before marking a ticket scheduled.');
        $db->beginTransaction(); q('UPDATE tickets SET status=?,assigned_to=?,scheduled_at=?,priority=?,updated_at=? WHERE id=? AND business_id=?',[$status,$assigned?:null,$schedule,$priority,now(),$t['id'],$biz]);
        activity((int)$t['id'],$user['name'].' updated the ticket: '.str_replace('_',' ',$status).($schedule?' · appointment '.$schedule:'').'.'); $db->commit(); flash('Ticket updated.'); go(['page'=>'ticket','id'=>$t['id']]);
    }
    if ($action==='note') { $t=ticket_access((int)($_POST['id']??0)); activity((int)$t['id'],$user['name'].': '.input('message',3000)); flash('Customer-visible update added.'); go(['page'=>'ticket','id'=>$t['id']]); }
    if ($action==='quote_create') {
        owner(); $t=scoped('tickets',(int)($_POST['ticket_id']??0)); $items=[]; $subtotal=0;
        $labels=$_POST['item_name']??[]; $quantities=$_POST['item_qty']??[]; $prices=$_POST['item_price']??[];
        if (!is_array($labels)||!is_array($quantities)||!is_array($prices)||count($labels)>20) throw new DomainException('Use at most 20 quote line items.');
        foreach ($labels as $i=>$label) { $label=trim((string)$label); if ($label==='') continue; if (strlen($label)>200) throw new DomainException('Line item description is too long.'); $qty=filter_var($quantities[$i]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]); if ($qty===false) throw new DomainException('Quantities must be whole numbers between 1 and 999.'); $price=cents(trim((string)($prices[$i]??''))); $line=$qty*$price; $subtotal+=$line; $items[]=['name'=>$label,'qty'=>$qty,'price'=>$price,'total'=>$line]; }
        if (!$items||$subtotal<=0||$subtotal>999999999) throw new DomainException('Add priced line items with a total under 10,000,000.');
        $rate=cents(input('tax_rate',10)); if ($rate>10000) throw new DomainException('Tax must be between 0 and 100 percent.'); $tax=(int)round($subtotal*$rate/10000); if ($subtotal+$tax>2000000000) throw new DomainException('Quote total is too large.');
        q('INSERT INTO documents (business_id,ticket_id,kind,status,items,subtotal,tax_rate,tax,total,due_date,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$biz,$t['id'],'quote','draft',json_encode($items,JSON_THROW_ON_ERROR),$subtotal,$rate,$tax,$subtotal+$tax,date_input('due_date'),input('notes',3000,false),now()]);
        flash('Draft quote created. Review it, then send it to the customer tracking page.'); go(['page'=>'ticket','id'=>$t['id']]);
    }
    if ($action==='document_send') {
        owner(); $d=scoped('documents',(int)($_POST['id']??0)); if ($d['status']!=='draft') throw new DomainException('This document has already been issued.');
        q("UPDATE documents SET status='sent' WHERE id=? AND business_id=? AND status='draft'",[$d['id'],$biz]); activity((int)$d['ticket_id'],doc_number($d).' issued for customer review.'); flash('Document is now visible on the customer tracking page. Copy the tracking link to share it.'); go(['page'=>'ticket','id'=>$d['ticket_id']]);
    }
    if ($action==='invoice_create') {
        owner(); $d=scoped('documents',(int)($_POST['id']??0)); if ($d['kind']!=='quote'||$d['status']!=='accepted') throw new DomainException('A customer must accept the quote before invoicing.');
        if (one('SELECT id FROM documents WHERE quote_id=?',[$d['id']])) throw new DomainException('This quote already has an invoice.');
        q('INSERT INTO documents (business_id,ticket_id,kind,status,items,subtotal,tax_rate,tax,total,due_date,notes,quote_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$biz,$d['ticket_id'],'invoice','draft',$d['items'],$d['subtotal'],$d['tax_rate'],$d['tax'],$d['total'],date_input('due_date',true),input('notes',3000,false),$d['id'],now()]);
        flash('Invoice draft created. Issue it to make it visible to the customer.'); go(['page'=>'ticket','id'=>$d['ticket_id']]);
    }
    if ($action==='payment') {
        owner(); $id=(int)($_POST['id']??0); $amount=cents(input('amount',20)); $method=enum_value('method',['bank_transfer','cash','card','other']); $reference=input('reference',160,false);
        $db->beginTransaction(); $d=one('SELECT * FROM documents WHERE id=? AND business_id=?'.($mysql?' FOR UPDATE':''),[$id,$biz]);
        if (!$d||$d['kind']!=='invoice'||!in_array($d['status'],['sent','partial'],true)) throw new DomainException('Only issued, unpaid invoices can receive payments.');
        if ($amount<=0||$amount>(int)$d['total']-(int)$d['paid']) throw new DomainException('Payment must be greater than zero and no more than the outstanding balance.');
        $paid=(int)$d['paid']+$amount; q('INSERT INTO payments (business_id,document_id,amount,method,reference,created_at) VALUES (?,?,?,?,?,?)',[$biz,$id,$amount,$method,$reference,now()]); q('UPDATE documents SET paid=?,status=? WHERE id=? AND business_id=?',[$paid,$paid===(int)$d['total']?'paid':'partial',$id,$biz]); activity((int)$d['ticket_id'],'Payment recorded for '.doc_number($d).'.'); $db->commit(); flash('Payment recorded.'); go(['page'=>'ticket','id'=>$d['ticket_id']]);
    }
    if ($action==='team_create') { owner(); $email=email_input(); if (one('SELECT id FROM users WHERE email=?',[$email])) throw new DomainException('Email is already registered.'); q('INSERT INTO users (business_id,name,email,password,role,created_at) VALUES (?,?,?,?,?,?)',[$biz,input('name',150),$email,password_hash(valid_password(),PASSWORD_DEFAULT),'technician',now()]); flash('Technician account created. Share their initial password securely.'); go(['page'=>'team']); }
    if ($action==='settings') { owner(); q('UPDATE businesses SET name=?,email=?,phone=? WHERE id=?',[input('company',150),email_input(),input('phone',60,false),$biz]); flash('Business details saved.'); go(['page'=>'settings']); }
    if ($action==='password') { if (!password_verify((string)($_POST['current_password']??''),$user['password'])) throw new DomainException('Current password is incorrect.'); q('UPDATE users SET password=? WHERE id=?',[password_hash(valid_password(),PASSWORD_DEFAULT),$user['id']]); session_regenerate_id(true); flash('Password updated.'); go(['page'=>'settings']); }
    throw new DomainException('Unknown action.');
}
