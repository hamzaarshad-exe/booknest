<?php
declare(strict_types=1);
require __DIR__.'/../src/app/core.php';
$configFile=getenv('BOOKNEST_TEST_CONFIG');
if(!$configFile || !is_file($configFile))throw new RuntimeException('Set BOOKNEST_TEST_CONFIG to a dedicated test database configuration.');
$GLOBALS['booknest_config']=require $configFile;
if(!str_ends_with(config()['db_name'],'_test'))throw new RuntimeException('Test database name must end in _test. Never run against production.');
require __DIR__.'/../src/app/booking.php';
$GLOBALS['test_passes']=0;
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['test_passes']++;echo 'PASS '.$label."\n";}
function rejects(callable $fn,string $label): void {try{$fn();}catch(ValidationError){check(true,$label);return;}throw new RuntimeException('FAIL expected rejection: '.$label);}

if(($argv[1] ?? '')==='worker'){
    $data=json_decode(base64_decode($argv[2]),true);$_SESSION=[];
    try{create_booking($data,bin2hex(random_bytes(32)),bin2hex(random_bytes(32)));echo 'created';exit(0);}catch(ValidationError){echo 'blocked';exit(2);}
}

$schema=require __DIR__.'/../src/app/schema.php';foreach(explode(';',$schema) as $sql)if(trim($sql)!=='')db()->exec($sql);
if((int)one('SELECT COUNT(*) AS n FROM bk_users')['n']>0)throw new RuntimeException('Use a fresh test database.');
foreach(['timezone'=>'Asia/Karachi','currency'=>'PKR','schedule_mutex'=>'1','slot_interval'=>'15','booking_window_days'=>'365','min_notice_minutes'=>'60','cancellation_notice_minutes'=>'120','auto_confirm'=>'1'] as $k=>$v)set_setting($k,$v);
run("INSERT INTO bk_users (name,email,password_hash,created_at) VALUES ('Test owner','owner@example.test',?,UTC_TIMESTAMP())",[password_hash('A test password 123',PASSWORD_DEFAULT)]);$uid=(int)db()->lastInsertId();
$_SESSION=['user_id'=>$uid,'session_version'=>1,'last_seen'=>time()];$_SERVER['REMOTE_ADDR']='127.0.0.1';
for($d=1;$d<=7;$d++)run("INSERT INTO bk_hours (weekday,opens,closes,closed) VALUES (?,'10:00:00','18:00:00',?)",[$d,$d===7?1:0]);
run("INSERT INTO bk_staff (name,role_title,active,created_at) VALUES ('Alex','Consultant',1,UTC_TIMESTAMP()),('Sam','Consultant',1,UTC_TIMESTAMP())");
run("INSERT INTO bk_services (name,description,duration_minutes,buffer_minutes,price_minor,active,created_at) VALUES ('Consultation','Test',30,15,250000,1,UTC_TIMESTAMP())");
run('INSERT INTO bk_service_staff (service_id,staff_id) VALUES (1,1),(1,2)');
check(money_minor('10.99')===1099 && money_minor('0.1')===10,'Exact two-decimal money parsing');
rejects(fn()=>money_minor('-1'),'Negative prices rejected');rejects(fn()=>money_minor('10.999'),'Extra decimal places rejected');
rejects(fn()=>parse_local('2026-02-30','10:00'),'Invalid calendar dates rejected');
check(e('<script>')==='&lt;script&gt;','HTML escaped at output');
check(password_input(['p'=>'  a long password  '],'p',12)==='  a long password  ','Password whitespace preserved');
rejects(fn()=>password_input(['p'=>str_repeat('x',73)],'p',12),'Bcrypt length truncation prevented');
$day=local_now()->setTime(0,0)->modify('+2 days');while($day->format('N')==='7')$day=$day->modify('+1 day');$date=$day->format('Y-m-d');$service=service_by_id(1);
check(count(available_slots($service,1,$date))===30,'Duration and buffer fit within opening hours');
$sunday=$day;while($sunday->format('N')!=='7')$sunday=$sunday->modify('+1 day');check(available_slots($service,1,$sunday->format('Y-m-d'))===[],'Closed days unavailable');
check(available_slots($service,1,local_now()->modify('-1 day')->format('Y-m-d'))===[],'Past dates unavailable');
$data=['service_id'=>1,'staff_id'=>1,'date'=>$date,'time'=>'10:00','client_name'=>'First Customer','client_phone'=>'+923001234567','client_email'=>'customer@example.test','notes'=>'Test appointment'];
$key=bin2hex(random_bytes(32));$token=bin2hex(random_bytes(32));$b=create_booking($data,$key,$token);
check($b['status']==='confirmed' && $b['starts_at']===$date.' 05:00:00','Booking confirmed with correct UTC storage');
check(booking_for_token($token)['id']===$b['id'] && booking_for_token(str_repeat('0',64))===null,'Private management token validation');
check(create_booking($data,$key,$token)['id']===$b['id'],'Idempotent booking retry');
rejects(fn()=>create_booking($data,bin2hex(random_bytes(32)),bin2hex(random_bytes(32))),'Exact double booking blocked');
$overlap=$data;$overlap['time']='10:30';rejects(fn()=>create_booking($overlap,bin2hex(random_bytes(32)),bin2hex(random_bytes(32))),'Buffer overlap blocked');
$other=$data;$other['staff_id']=2;check((bool)create_booking($other,bin2hex(random_bytes(32)),bin2hex(random_bytes(32))),'Different team member can use the same time');
run('UPDATE bk_services SET price_minor=300000,duration_minutes=45 WHERE id=1');
check((int)one('SELECT * FROM bk_appointments WHERE id=?',[$b['id']])['price_minor']===250000,'Existing booking price preserved');
reschedule_booking((int)$b['id'],1,$date,'11:00');$moved=one('SELECT * FROM bk_appointments WHERE id=?',[$b['id']]);
check((int)$moved['duration_minutes']===30 && (int)$moved['price_minor']===250000 && $moved['starts_at']===$date.' 06:00:00','Reschedule preserves duration and price');
check(in_array('10:00',array_column(available_slots(service_by_id(1),1,$date),'time'),true),'Reschedule frees original slot');
rejects(fn()=>change_booking_status((int)$b['id'],'completed'),'Future appointment cannot be marked completed');
record_payment((int)$b['id'],100000,'payment','bank','test deposit');check(payment_total((int)$b['id'])===100000,'Partial payment recorded');
rejects(fn()=>record_payment((int)$b['id'],200000,'payment','cash',''),'Overpayment rejected');
rejects(fn()=>record_payment((int)$b['id'],100001,'refund','bank',''),'Excess refund rejected');
record_payment((int)$b['id'],30000,'refund','bank','partial return');check(payment_total((int)$b['id'])===70000,'Refund reduces net received');
cancel_with_token($token);check(booking_for_token($token)['status']==='cancelled','Customer cancellation updates booking');
rejects(fn()=>record_payment((int)$b['id'],1000,'payment','cash',''),'Payments on cancelled booking rejected');
record_payment((int)$b['id'],70000,'refund','bank','remaining refund');check(payment_total((int)$b['id'])===0,'Refund supported after cancellation');
rejects(fn()=>change_booking_status((int)$b['id'],'confirmed'),'Cancelled booking cannot silently reactivate');
$offStart=parse_local($date,'12:00');run('INSERT INTO bk_time_off (staff_id,starts_at,ends_at,note) VALUES (?,?,?,?)',[1,utc_string($offStart),utc_string($offStart->modify('+1 hour')),'Lunch']);
check(!in_array('12:00',array_column(available_slots(service_by_id(1),1,$date),'time'),true),'Staff time off removes overlapping slots');
set_setting('auto_confirm','0');$pendingData=$data;$pendingData['time']='14:00';$pending=create_booking($pendingData,bin2hex(random_bytes(32)),bin2hex(random_bytes(32)));check($pending['status']==='pending','Manual confirmation creates pending request');
rejects(fn()=>create_booking($pendingData,bin2hex(random_bytes(32)),bin2hex(random_bytes(32))),'Pending request holds its slot');
run('UPDATE bk_staff SET active=0 WHERE id=2');check(available_slots(service_by_id(1),2,$date)===[],'Hidden team member cannot receive bookings');
run('UPDATE bk_staff SET active=1 WHERE id=2');
$race=$data;$race['time']='16:00';$processes=[];
for($i=0;$i<6;$i++){
    $cmd=[PHP_BINARY,'-n'];foreach(['pdo','mysqlnd','pdo_mysql'] as $ext){$cmd[]='-d';$cmd[]='extension='.getenv('BOOKNEST_PHP_EXT').'/'.$ext.'.so';}
    $cmd[]=__FILE__;$cmd[]='worker';$cmd[]=base64_encode(json_encode($race));$pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$processes[]=[$proc,$pipes];
}
$created=0;$blocked=0;foreach($processes as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($exit===0 && str_contains($out,'created'))$created++;elseif($exit===2 && str_contains($out,'blocked'))$blocked++;else throw new RuntimeException('Worker failure: '.$err.$out);}
check($created===1 && $blocked===5,'Six concurrent requests produce exactly one booking');
check((int)one('SELECT COUNT(*) AS n FROM bk_appointments WHERE staff_id=1 AND starts_at=?',[utc_string(parse_local($date,'16:00'))])['n']===1,'Concurrent booking persisted exactly once');
echo 'TOTAL '.$GLOBALS['test_passes']." checks passed.\n";
