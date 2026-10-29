<?php
declare(strict_types=1);

function service_by_id(int $id): ?array { return one('SELECT * FROM bk_services WHERE id=? AND active=1',[$id]); }
function service_staff(int $id): array { return rows('SELECT s.* FROM bk_staff s JOIN bk_service_staff ss ON ss.staff_id=s.id WHERE ss.service_id=? AND s.active=1 ORDER BY s.name,s.id',[$id]); }
function staff_can_book(int $serviceId, int $staffId): bool { return (bool)one('SELECT s.id FROM bk_staff s JOIN bk_service_staff ss ON ss.staff_id=s.id WHERE ss.service_id=? AND ss.staff_id=? AND s.active=1',[$serviceId,$staffId]); }
function date_allowed(string $date, bool $admin=false): bool {
    $today=local_now()->format('Y-m-d');$max=local_now()->modify('+'.(int)setting('booking_window_days','60').' days')->format('Y-m-d');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)===1 && $date>=$today && $date<=$max;
}
function available_slots(array $service, int $staffId, string $date, bool $admin=false, int $excludeId=0): array {
    if (!date_allowed($date,$admin) || !staff_can_book((int)$service['id'],$staffId)) return [];
    try {$day=parse_local($date,'00:00');}catch(ValidationError){return [];}
    $hours=one('SELECT * FROM bk_hours WHERE weekday=?',[(int)$day->format('N')]);
    if (!$hours || (int)$hours['closed']) return [];
    $opens=parse_local($date,substr($hours['opens'],0,5));$closes=parse_local($date,substr($hours['closes'],0,5));
    $duration=(int)$service['duration_minutes'];$buffer=(int)$service['buffer_minutes'];
    $cutoff=now_utc()->modify('+'.($admin?0:(int)setting('min_notice_minutes','60')).' minutes');
    $busy=rows("SELECT starts_at,busy_until AS ends_at FROM bk_appointments WHERE staff_id=? AND id<>? AND status IN ('pending','confirmed','completed') AND starts_at<? AND busy_until>?",[$staffId,$excludeId,utc_string($closes),utc_string($opens)]);
    $off=rows('SELECT starts_at,ends_at FROM bk_time_off WHERE (staff_id IS NULL OR staff_id=?) AND starts_at<? AND ends_at>?',[$staffId,utc_string($closes),utc_string($opens)]);
    $blocked=array_merge($busy,$off);$out=[];$seen=[];
    for ($start=$opens;$start<$closes;$start=$start->modify('+'.(int)setting('slot_interval','15').' minutes')) {
        $end=$start->modify('+'.$duration.' minutes');$busyEnd=$end->modify('+'.$buffer.' minutes');
        if ($busyEnd>$closes || $start<$cutoff) continue;
        $s=utc_string($start);$b=utc_string($busyEnd);$fits=true;
        foreach($blocked as $range) if ($range['starts_at']<$b && $range['ends_at']>$s){$fits=false;break;}
        $time=$start->format('H:i');
        if ($fits && !isset($seen[$time])) {$out[]=['time'=>$time,'label'=>$start->format('g:i a'),'starts_at'=>$s,'ends_at'=>utc_string($end),'busy_until'=>$b];$seen[$time]=true;}
    }
    return $out;
}
function create_booking(array $data, string $requestKey, string $token, bool $admin=false): array {
    $serviceId=int_input($data,'service_id',1,PHP_INT_MAX);$staffId=int_input($data,'staff_id',1,PHP_INT_MAX);
    $date=text_input($data,'date',10);$time=text_input($data,'time',5);
    $name=text_input($data,'client_name',100);$phone=phone_input(text_input($data,'client_phone',30));$email=text_input($data,'client_email',190,false);$notes=text_input($data,'notes',1200,false);
    if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new ValidationError('Enter a valid email address.');
    parse_local($date,$time);
    db()->beginTransaction();
    try {
        // Every schedule mutation takes this mutex first, including staff time off.
        // Take it BEFORE any consistent read: InnoDB REPEATABLE READ otherwise
        // keeps a snapshot from before the previous booking committed.
        one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
        $old=one('SELECT * FROM bk_appointments WHERE request_key=? FOR UPDATE',[$requestKey]);
        if ($old) {db()->commit();return $old;}
        $service=one('SELECT * FROM bk_services WHERE id=? AND active=1 FOR UPDATE',[$serviceId]);
        $staff=one('SELECT * FROM bk_staff WHERE id=? AND active=1 FOR UPDATE',[$staffId]);
        if (!$service || !$staff) throw new ValidationError('This service or team member is no longer available.');
        unset($GLOBALS['booknest_settings']);
        $slots=available_slots($service,$staffId,$date,$admin);$selected=null;
        foreach($slots as $slot) if ($slot['time']===$time){$selected=$slot;break;}
        if (!$selected) throw new ValidationError('That time is no longer available. Please choose another slot.');
        $reference='BN-'.local_now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        $status=$admin || setting('auto_confirm','1')==='1'?'confirmed':'pending';
        run('INSERT INTO bk_appointments (reference,token_hash,request_key,service_id,staff_id,service_name,duration_minutes,buffer_minutes,price_minor,currency_code,client_name,client_phone,client_email,notes,starts_at,ends_at,busy_until,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$reference,hash('sha256',$token),$requestKey,$serviceId,$staffId,$service['name'],$service['duration_minutes'],$service['buffer_minutes'],$service['price_minor'],setting('currency','PKR'),$name,$phone,$email,$notes,$selected['starts_at'],$selected['ends_at'],$selected['busy_until'],$status]);
        $booking=one('SELECT * FROM bk_appointments WHERE id=?',[(int)db()->lastInsertId()]);
        audit('booking.created',$reference.' '.$status);
        db()->commit();return $booking;
    } catch(Throwable $e) {if(db()->inTransaction())db()->rollBack();throw $e;}
}
function booking_for_token(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/',$token)) return null;
    return one('SELECT a.*,s.name AS staff_name FROM bk_appointments a JOIN bk_staff s ON s.id=a.staff_id WHERE a.token_hash=?',[hash('sha256',$token)]);
}
function cancel_with_token(string $token): void {
    db()->beginTransaction();
    try {
        one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
        $b=booking_for_token($token);
        if (!$b || !in_array($b['status'],['pending','confirmed'],true)) throw new ValidationError('This booking cannot be cancelled online.');
        $cutoff=now_utc()->modify('+'.(int)setting('cancellation_notice_minutes','120').' minutes');
        if ($b['starts_at']<=utc_string($cutoff)) throw new ValidationError('The online cancellation window has closed. Please contact the business.');
        run("UPDATE bk_appointments SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=?",[$b['id']]);
        audit('booking.cancelled',$b['reference'].' customer');db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function change_booking_status(int $id, string $status): void {
    db()->beginTransaction();
    try {
        one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
        $b=one('SELECT * FROM bk_appointments WHERE id=? FOR UPDATE',[$id]);
        $allowed=['pending'=>['confirmed','cancelled'],'confirmed'=>['completed','no_show','cancelled'],'completed'=>[],'cancelled'=>[],'no_show'=>[]];
        if (!$b || !in_array($status,$allowed[$b['status']] ?? [],true)) throw new ValidationError('That status change is not available.');
        if(in_array($status,['completed','no_show'],true) && $b['starts_at']>utc_string(now_utc())) throw new ValidationError('Mark an appointment completed or no-show after its start time.');
        run('UPDATE bk_appointments SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$status,$id]);
        audit('booking.status',$b['reference'].' '.$b['status'].' -> '.$status);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function record_payment(int $id, int $amount, string $type, string $method, string $note): void {
    if($amount<=0 || !in_array($type,['payment','refund'],true) || !in_array($method,['cash','bank','easypaisa','jazzcash','other'],true)) throw new ValidationError('Please check the payment details.');
    db()->beginTransaction();
    try {
        $b=one('SELECT * FROM bk_appointments WHERE id=? FOR UPDATE',[$id]);
        if(!$b) throw new ValidationError('Booking not found.');
        $paid=payment_total($id);
        if($type==='payment' && ($amount>(int)$b['price_minor']-$paid || $b['status']==='cancelled')) throw new ValidationError('The payment exceeds the balance, or this booking is cancelled.');
        if($type==='refund' && $amount>$paid) throw new ValidationError('A refund cannot exceed the amount received.');
        run('INSERT INTO bk_payments (appointment_id,amount_minor,entry_type,method,note,recorded_by,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',[$id,$amount,$type,$method,$note,require_user()['id']]);
        audit('payment.'.$type,$b['reference'].' '.raw_amount($amount).' '.$b['currency_code']);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}

function can_customer_change(array $b): bool {
    return in_array($b['status'],['pending','confirmed'],true) && $b['starts_at']>utc_string(now_utc()->modify('+'.(int)setting('cancellation_notice_minutes','120').' minutes'));
}
function reschedule_booking(int $id, int $staffId, string $date, string $time, bool $admin=false): void {
    db()->beginTransaction();
    try {
        one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
        $b=one('SELECT * FROM bk_appointments WHERE id=? FOR UPDATE',[$id]);
        if(!$b || !in_array($b['status'],['pending','confirmed'],true) || (!$admin && !can_customer_change($b)))throw new ValidationError('This booking cannot be changed online. Please contact the business.');
        $service=service_by_id((int)$b['service_id']);
        if(!$service)throw new ValidationError('This service is no longer available online. Please contact the business.');
        $service['duration_minutes']=$b['duration_minutes'];$service['buffer_minutes']=$b['buffer_minutes'];
        one('SELECT id FROM bk_staff WHERE id=? FOR UPDATE',[$staffId]);
        $selected=null;
        foreach(available_slots($service,$staffId,$date,$admin,$id) as $slot)if($slot['time']===$time){$selected=$slot;break;}
        if(!$selected)throw new ValidationError('That time is no longer available. Please choose another slot.');
        run('UPDATE bk_appointments SET staff_id=?,starts_at=?,ends_at=?,busy_until=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$staffId,$selected['starts_at'],$selected['ends_at'],$selected['busy_until'],$id]);
        audit('booking.rescheduled',$b['reference'].' '.$selected['starts_at']);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
