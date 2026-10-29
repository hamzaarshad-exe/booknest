<?php
declare(strict_types=1);

function handle_post(): void {
    $action=is_string($_POST['action'] ?? null)?$_POST['action']:'';
    $back='dashboard';$backParams=[];
    try {
        verify_csrf();
        if($action==='login') {
            $back='login';$email=strtolower(text_input($_POST,'email',190));
            rate_limit('login-ip',25);rate_limit('login-account',8,900,$email);
            $password=password_input($_POST,'password');
            $u=one('SELECT * FROM bk_users WHERE email=?',[$email]);
            $hash=$u['password_hash'] ?? '$2y$10$EJ7se5U4v6Eoc5QnD0xLoOVM6hOLK1YBWk01HJUZOMEEY4MB8KBTq';
            if(!password_verify($password,$hash) || !$u) throw new ValidationError('Email or password is incorrect.');
            session_regenerate_id(true);
            $_SESSION['user_id']=(int)$u['id'];$_SESSION['session_version']=(int)$u['session_version'];$_SESSION['last_seen']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));
            redirect('dashboard');
        }
        if($action==='book') {
            $back='book';
            foreach(['service','staff','date','time'] as $k){$key=['service'=>'service_id','staff'=>'staff_id'][$k] ?? $k;if(is_scalar($_POST[$key] ?? null))$backParams[$k]=(string)$_POST[$key];}
            if (trim((string)($_POST['website'] ?? ''))!=='') throw new ValidationError('Unable to accept this booking.');
            $nonce=text_input($_POST,'nonce',64);
            $requestKey=hash('sha256',session_id().':'.$nonce);
            if(isset($_SESSION['receipts'][$nonce])) redirect('manage',['token'=>$_SESSION['receipts'][$nonce]]);
            if(!isset($_SESSION['booking_nonces'][$nonce]) || time()-$_SESSION['booking_nonces'][$nonce]>7200) throw new ValidationError('This booking form has expired. Select a time and try again.');
            rate_limit('booking',10);
            $token=hash_hmac('sha256',$requestKey,config()['app_secret']);
            $b=create_booking($_POST,$requestKey,$token);
            $_SESSION['receipts'][$nonce]=$token;unset($_SESSION['booking_nonces'][$nonce],$_SESSION['booking_old']);
            if(count($_SESSION['receipts'])>10)array_shift($_SESSION['receipts']);
            flash($b['status']==='pending'?'Your request is saved. The business will confirm your appointment.':'Your appointment is confirmed.');
            redirect('manage',['token'=>$token]);
        }
        if($action==='customer_cancel') {
            $back='manage';$token=text_input($_POST,'token',64);$backParams=['token'=>$token];
            rate_limit('manage',30);cancel_with_token($token);flash('Your appointment has been cancelled. Contact the business about any payment refund.');redirect($back,$backParams);
        }
        if($action==='move') {
            $token=text_input($_POST,'token',64,false);$id=(int)($_POST['id'] ?? 0);
            $back='move';$backParams=$token!==''?['token'=>$token]:['id'=>$id];
            $admin=$token==='';if($admin)require_user();else rate_limit('move',20);
            $b=$admin?one('SELECT * FROM bk_appointments WHERE id=?',[$id]):booking_for_token($token);
            if(!$b)throw new ValidationError('Booking not found.');
            reschedule_booking((int)$b['id'],int_input($_POST,'staff_id',1,PHP_INT_MAX),text_input($_POST,'date',10),text_input($_POST,'time',5),$admin);
            flash('The appointment time has been updated.');redirect($admin?'appointment':'manage',$admin?['id'=>$b['id']]:['token'=>$token]);
        }
        $u=require_user();
        switch($action) {
            case 'logout':
                $_SESSION=[];session_regenerate_id(true);flash('You have signed out.','info');redirect('login');
            case 'service_save':
                $back='services';$id=(int)($_POST['id'] ?? 0);
                $name=text_input($_POST,'name',120);$description=text_input($_POST,'description',600,false);$duration=int_input($_POST,'duration_minutes',5,480);$buffer=int_input($_POST,'buffer_minutes',0,120);$price=money_minor(text_input($_POST,'price',15));$active=isset($_POST['active'])?1:0;
                $staffIds=$_POST['staff_ids'] ?? [];
                if(!is_array($staffIds))throw new ValidationError('Choose a valid team member.');
                $staffIds=array_values(array_unique(array_map('intval',$staffIds)));
                if($active && !$staffIds)throw new ValidationError('Assign at least one team member to an active service.');
                foreach($staffIds as $sid)if(!one('SELECT id FROM bk_staff WHERE id=? AND active=1',[$sid]))throw new ValidationError('Choose active team members.');
                db()->beginTransaction();one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
                if($id>0){if(!one('SELECT id FROM bk_services WHERE id=?',[$id]))throw new ValidationError('Service not found.');run('UPDATE bk_services SET name=?,description=?,duration_minutes=?,buffer_minutes=?,price_minor=?,active=? WHERE id=?',[$name,$description,$duration,$buffer,$price,$active,$id]);}
                else{run('INSERT INTO bk_services (name,description,duration_minutes,buffer_minutes,price_minor,active,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',[$name,$description,$duration,$buffer,$price,$active]);$id=(int)db()->lastInsertId();}
                run('DELETE FROM bk_service_staff WHERE service_id=?',[$id]);foreach($staffIds as $sid)run('INSERT INTO bk_service_staff (service_id,staff_id) VALUES (?,?)',[$id,$sid]);audit('service.saved','Service #'.$id);db()->commit();flash('Service saved. Existing booking prices and durations stay as booked.');break;
            case 'staff_save':
                $back='staff';$id=(int)($_POST['id'] ?? 0);$name=text_input($_POST,'name',100);$role=text_input($_POST,'role_title',100,false);$active=isset($_POST['active'])?1:0;
                db()->beginTransaction();one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
                if($id>0){if(!one('SELECT id FROM bk_staff WHERE id=?',[$id]))throw new ValidationError('Team member not found.');run('UPDATE bk_staff SET name=?,role_title=?,active=? WHERE id=?',[$name,$role,$active,$id]);}
                else{run('INSERT INTO bk_staff (name,role_title,active,created_at) VALUES (?,?,?,UTC_TIMESTAMP())',[$name,$role,$active]);$id=(int)db()->lastInsertId();}
                audit('staff.saved','Team member #'.$id);db()->commit();flash('Team member saved. Assign their services on the Services page.');break;
            case 'hours_save':
                $back='schedule';$hours=[];
                for($day=1;$day<=7;$day++) {
                    $open=text_input($_POST,'opens_'.$day,5);$close=text_input($_POST,'closes_'.$day,5);$closed=isset($_POST['closed_'.$day])?1:0;
                    if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$open) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$close) || (!$closed && $open>=$close))throw new ValidationError('Closing time must be after opening time. Overnight hours are not supported.');
                    $hours[]=[$day,$open,$close,$closed];
                }
                db()->beginTransaction();one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
                foreach($hours as $h)run('INSERT INTO bk_hours (weekday,opens,closes,closed) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE opens=VALUES(opens),closes=VALUES(closes),closed=VALUES(closed)',$h);
                audit('schedule.saved','Weekly opening hours');db()->commit();flash('Hours saved. Existing appointments are preserved; review them if your hours changed.');break;
            case 'time_off_add':
                $back='schedule';$date=text_input($_POST,'date',10);$dateEnd=text_input($_POST,'end_date',10);$start=parse_local($date,text_input($_POST,'start_time',5));$end=parse_local($dateEnd,text_input($_POST,'end_time',5));$staffId=(int)($_POST['staff_id'] ?? 0);$note=text_input($_POST,'note',180,false);
                if($end<=$start || $end>$start->modify('+366 days'))throw new ValidationError('Choose an end after the start, within one year.');
                if($staffId && !one('SELECT id FROM bk_staff WHERE id=?',[$staffId]))throw new ValidationError('Team member not found.');
                db()->beginTransaction();one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
                $overlaps=one("SELECT COUNT(*) AS n FROM bk_appointments WHERE status IN ('pending','confirmed') AND starts_at<? AND busy_until>?".($staffId?' AND staff_id=?':''),$staffId?[utc_string($end),utc_string($start),$staffId]:[utc_string($end),utc_string($start)]);
                if((int)$overlaps['n']>0)throw new ValidationError('This time off overlaps existing appointments. Reschedule or cancel those bookings first.');
                run('INSERT INTO bk_time_off (staff_id,starts_at,ends_at,note) VALUES (?,?,?,?)',[$staffId ?: null,utc_string($start),utc_string($end),$note]);audit('schedule.time_off',$date.' '.$dateEnd);db()->commit();flash('Time off added. These slots are now unavailable for booking.');break;
            case 'time_off_delete':
                $back='schedule';$id=int_input($_POST,'id',1,PHP_INT_MAX);db()->beginTransaction();one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");run('DELETE FROM bk_time_off WHERE id=?',[$id]);audit('schedule.time_off_removed','Time off #'.$id);db()->commit();flash('Time off removed.');break;
            case 'booking_status':
                $id=int_input($_POST,'id',1,PHP_INT_MAX);$back='appointment';$backParams=['id'=>$id];change_booking_status($id,text_input($_POST,'status',20));flash('Appointment updated.');break;
            case 'payment_add':
                $id=int_input($_POST,'id',1,PHP_INT_MAX);$back='appointment';$backParams=['id'=>$id];record_payment($id,money_minor(text_input($_POST,'amount',15)),text_input($_POST,'entry_type',12),text_input($_POST,'method',20),text_input($_POST,'note',250,false));flash('Payment entry recorded. No money is transferred by this action.');break;
            case 'settings_save':
                $back='settings';$values=[];
                foreach(['business_name'=>100,'tagline'=>180,'contact_email'=>190,'phone'=>30,'whatsapp'=>20,'address'=>400,'payment_instructions'=>1200,'cancellation_policy'=>1000] as $k=>$max)$values[$k]=text_input($_POST,$k,$max,in_array($k,['business_name'],true));
                if($values['contact_email']!=='' && !filter_var($values['contact_email'],FILTER_VALIDATE_EMAIL))throw new ValidationError('Enter a valid business email address.');
                if($values['whatsapp']!=='' && !preg_match('/^[1-9][0-9]{6,14}$/',$values['whatsapp']))throw new ValidationError('Use the WhatsApp country code and number, with digits only.');
                $values['currency']=valid_currency(text_input($_POST,'currency',3));$values['timezone']=text_input($_POST,'timezone',70);
                if(!in_array($values['timezone'],DateTimeZone::listIdentifiers(),true))throw new ValidationError('Choose a valid timezone.');
                foreach(['booking_window_days'=>[1,365],'min_notice_minutes'=>[0,10080],'cancellation_notice_minutes'=>[0,10080]] as $k=>[$min,$max])$values[$k]=(string)int_input($_POST,$k,$min,$max);
                $values['slot_interval']=(string)int_input($_POST,'slot_interval',5,120);$values['auto_confirm']=isset($_POST['auto_confirm'])?'1':'0';
                db()->beginTransaction();one("SELECT setting_key FROM bk_settings WHERE setting_key='schedule_mutex' FOR UPDATE");
                if($values['timezone']!==setting('timezone') && (int)(one("SELECT COUNT(*) AS n FROM bk_appointments WHERE starts_at>UTC_TIMESTAMP() AND status IN ('pending','confirmed')")['n'])>0)throw new ValidationError('Timezone cannot change while future active bookings exist. Reschedule or cancel them first.');
                foreach($values as $k=>$v)set_setting($k,$v);audit('settings.saved','Business details and booking rules');db()->commit();flash('Business settings saved. Existing appointment currencies stay as booked.');break;
            case 'account_save':
                $back='account';$name=text_input($_POST,'name',100);$email=strtolower(text_input($_POST,'email',190));$current=password_input($_POST,'current_password');$password=password_input($_POST,'new_password',0);
                $owner=one('SELECT * FROM bk_users WHERE id=?',[$u['id']]);
                if(!password_verify($current,$owner['password_hash']))throw new ValidationError('Your current password is incorrect.');
                if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new ValidationError('Enter a valid email address.');
                if($password!=='' && strlen($password)<12)throw new ValidationError('Use a new password of at least 12 characters.');
                run('UPDATE bk_users SET name=?,email=?,password_hash=?,session_version=session_version+1 WHERE id=?',[$name,$email,$password!==''?password_hash($password,PASSWORD_DEFAULT):$owner['password_hash'],$u['id']]);
                audit('account.updated','Owner account');$_SESSION=[];session_regenerate_id(true);flash('Account updated. Sign in with your current credentials.');redirect('login');
            default:throw new ValidationError('That action is not available.');
        }
        redirect($back,$backParams);
    }catch(ValidationError $e){
        if(db()->inTransaction())db()->rollBack();
        if($action==='book')$_SESSION['booking_old']=array_intersect_key($_POST,array_flip(['client_name','client_phone','client_email','notes']));
        flash($e->getMessage(),'error');redirect($back,$backParams);
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();error_log('BookNest action failed: '.$e->getMessage());flash('We could not save that change. Please try again or contact the business.','error');redirect($back,$backParams);
    }
}
