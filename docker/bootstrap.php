<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=getenv('BOOKNEST_SOURCE_ROOT') ?: '/var/www/html';
require $root.'/app/core.php';
$pdo=null;$locked=false;

function environment_value(string $name,string $default=''): string {
    $value=getenv($name);
    return $value===false?$default:$value;
}
function validated_url(string $value): string {
    $url=rtrim($value,'/');$parts=parse_url($url);
    if(!filter_var($url,FILTER_VALIDATE_URL) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))throw new RuntimeException('Set BOOKNEST_APP_URL to the complete public HTTPS URL.');
    $loopback=in_array($parts['host'] ?? '',['localhost','127.0.0.1','[::1]'],true);
    if(($parts['scheme'] ?? '')!=='https' && !(($parts['scheme'] ?? '')==='http' && $loopback))throw new RuntimeException('BOOKNEST_APP_URL must use HTTPS, except for localhost tests.');
    return $url;
}

try {
    $appUrl=validated_url(environment_value('BOOKNEST_APP_URL'));
    $host=environment_value('BOOKNEST_DB_HOST','db');
    $port=filter_var(environment_value('BOOKNEST_DB_PORT','3306'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
    $dbName=environment_value('BOOKNEST_DB_NAME','booknest');$dbUser=environment_value('BOOKNEST_DB_USER','booknest');$dbPassword=environment_value('BOOKNEST_DB_PASSWORD');
    if(!preg_match('/^[a-zA-Z0-9.:-]+$/',$host) || !$port || !preg_match('/^[a-zA-Z0-9_\-]{1,64}$/',$dbName) || $dbUser==='' || $dbPassword==='')throw new RuntimeException('Set valid BookNest database environment values.');
    $file=config_file_path();$state=dirname($file);
    if(!is_dir($state) && !mkdir($state,0700,true))throw new RuntimeException('Cannot create BookNest state directory.');
    $existing=is_file($file)?require $file:[];
    if(!is_array($existing))throw new RuntimeException('Stored BookNest configuration is invalid.');
    for($attempt=0;$attempt<30;$attempt++) {
        try {$pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$dbName.';charset=utf8mb4',$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);break;}
        catch(PDOException){if($attempt===29)throw new RuntimeException('Database unavailable. Check Docker database settings and health.');sleep(1);}
    }
    $pdo->exec("SET time_zone='+00:00'");
    $lock=$pdo->prepare('SELECT GET_LOCK(?,30)');$lock->execute(['booknest_bootstrap_'.$dbName]);$locked=(int)$lock->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('Another BookNest instance is being initialized. Try again shortly.');
    $schema=require $root.'/app/schema.php';foreach(explode(';',$schema) as $sql)if(trim($sql)!=='')$pdo->exec($sql);
    $fresh=(int)$pdo->query('SELECT COUNT(*) FROM bk_users')->fetchColumn()===0;
    if($fresh) {
        foreach(['bk_settings','bk_staff','bk_services','bk_appointments','bk_payments'] as $table)if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()>0)throw new RuntimeException('The database contains BookNest data but no owner. Restore the matching database and configuration backup.');
        $business=text_input(['business_name'=>environment_value('BOOKNEST_BUSINESS_NAME','Your Business')],'business_name',100);
        $owner=text_input(['owner_name'=>environment_value('BOOKNEST_OWNER_NAME','Business Owner')],'owner_name',100);
        $email=strtolower(environment_value('BOOKNEST_OWNER_EMAIL'));$password=password_input(['password'=>environment_value('BOOKNEST_OWNER_PASSWORD')],'password',12);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190)throw new RuntimeException('Set a valid BOOKNEST_OWNER_EMAIL for first-time setup.');
        $timezone=environment_value('BOOKNEST_TIMEZONE','Asia/Karachi');$currency=valid_currency(environment_value('BOOKNEST_CURRENCY','PKR'));
        if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))throw new RuntimeException('Set a valid BOOKNEST_TIMEZONE.');
        $pdo->beginTransaction();
        $settings=['business_name'=>$business,'tagline'=>'Make time for what matters.','contact_email'=>$email,'phone'=>'','whatsapp'=>'','address'=>'','currency'=>$currency,'timezone'=>$timezone,'slot_interval'=>'15','booking_window_days'=>'60','min_notice_minutes'=>'60','cancellation_notice_minutes'=>'120','auto_confirm'=>'1','payment_instructions'=>'','cancellation_policy'=>'Please give us advance notice if your plans change. For refunds, contact the business directly.','schedule_mutex'=>'1'];
        $q=$pdo->prepare('INSERT INTO bk_settings (setting_key,setting_value) VALUES (?,?)');foreach($settings as $key=>$value)$q->execute([$key,$value]);
        $q=$pdo->prepare('INSERT INTO bk_users (name,email,password_hash,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');$q->execute([$owner,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $q=$pdo->prepare("INSERT INTO bk_staff (name,role_title,active,created_at) VALUES (?,'Your team',1,UTC_TIMESTAMP())");$q->execute([$business]);$staffId=(int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO bk_services (name,description,duration_minutes,buffer_minutes,price_minor,active,created_at) VALUES ('Consultation','Edit and enable this service before sharing your booking page.',30,0,0,0,UTC_TIMESTAMP())");$serviceId=(int)$pdo->lastInsertId();
        $q=$pdo->prepare('INSERT INTO bk_service_staff (service_id,staff_id) VALUES (?,?)');$q->execute([$serviceId,$staffId]);
        $q=$pdo->prepare("INSERT INTO bk_hours (weekday,opens,closes,closed) VALUES (?,'10:00:00','18:00:00',?)");for($day=1;$day<=7;$day++)$q->execute([$day,$day===7?1:0]);
    }
    $configuration=['db_host'=>$host,'db_port'=>(int)$port,'db_name'=>$dbName,'db_user'=>$dbUser,'db_password'=>$dbPassword,'app_url'=>$appUrl,'app_secret'=>$existing['app_secret'] ?? bin2hex(random_bytes(32)),'recovery_key'=>$existing['recovery_key'] ?? '','docker_recovery_key_hash'=>$existing['docker_recovery_key_hash'] ?? ''];
    $recovery=environment_value('BOOKNEST_RECOVERY_KEY');
    if($recovery!=='') {
        if(strlen($recovery)<32 || strlen($recovery)>200)throw new RuntimeException('BOOKNEST_RECOVERY_KEY must contain 32–200 characters.');
        $hash=hash('sha256',$recovery);
        if(!hash_equals($configuration['docker_recovery_key_hash'],$hash)){$configuration['recovery_key']=$recovery;$configuration['docker_recovery_key_hash']=$hash;}
    } else {$configuration['recovery_key']='';}
    $temporary=$state.'/config-bootstrap.php';$bytes="<?php\ndeclare(strict_types=1);\nreturn ".var_export($configuration,true).";\n";
    if(file_put_contents($temporary,$bytes,LOCK_EX)===false)throw new RuntimeException('Cannot save BookNest configuration.');
    chmod($temporary,0600);
    if($pdo->inTransaction())$pdo->commit();
    if(!rename($temporary,$file))throw new RuntimeException('Cannot activate BookNest configuration. Keep the state volume and correct its permissions.');
    echo $fresh?"BookNest initialized. Sign in with the owner account configured in Docker settings.\n":"BookNest ready. Existing accounts and bookings preserved.\n";
} catch(Throwable $e) {
    if($pdo?->inTransaction())$pdo->rollBack();
    fwrite(STDERR,'BookNest startup failed: '.$e->getMessage()."\n");exit(1);
} finally {
    if($locked && $pdo){$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute(['booknest_bootstrap_'.$dbName]);}
}
