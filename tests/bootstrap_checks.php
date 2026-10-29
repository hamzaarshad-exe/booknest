<?php
declare(strict_types=1);
$repo=dirname(__DIR__);$source=$repo.'/src';$bootstrap=$repo.'/docker/bootstrap.php';
require $source.'/app/core.php';
$base=json_decode(file_get_contents(getenv('BOOKNEST_BOOTSTRAP_TEST_JSON')),true);
if(!str_ends_with($base['db_name'],'_test'))throw new RuntimeException('Dedicated test database required.');
$php=[PHP_BINARY,'-n'];foreach(['pdo','mysqlnd','pdo_mysql'] as $mod){$php[]='-d';$php[]='extension='.getenv('BOOKNEST_PHP_EXT').'/'.$mod.'.so';}
$pdo=new PDO('mysql:host='.$base['db_host'].';port='.$base['db_port'].';dbname='.$base['db_name'].';charset=utf8mb4',$base['db_user'],$base['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$env=getenv();$env['BOOKNEST_SOURCE_ROOT']=$source;$env['BOOKNEST_CONFIG_FILE']=$base['config_file'];$env['BOOKNEST_APP_URL']='https://booking.example.test';$env['BOOKNEST_DB_HOST']=$base['db_host'];$env['BOOKNEST_DB_PORT']=(string)$base['db_port'];$env['BOOKNEST_DB_NAME']=$base['db_name'];$env['BOOKNEST_DB_USER']=$base['db_user'];$env['BOOKNEST_DB_PASSWORD']=$base['db_password'];$env['BOOKNEST_OWNER_EMAIL']='owner@example.test';$env['BOOKNEST_OWNER_PASSWORD']='A private test password 123';$env['BOOKNEST_BUSINESS_NAME']='Bootstrap Test Business';$env['BOOKNEST_OWNER_NAME']='Test Owner';$env['BOOKNEST_TIMEZONE']='Asia/Karachi';$env['BOOKNEST_CURRENCY']='PKR';$env['BOOKNEST_RECOVERY_KEY']='';
$count=0;
function expect(bool $condition,string $label):void{global $count;if(!$condition)throw new RuntimeException('FAIL '.$label);$count++;echo 'PASS '.$label."\n";}
function boot(array $settings):array{global $php,$bootstrap;$pipes=[];$p=proc_open([...$php,$bootstrap],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$settings);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];}
function saved():array{global $base;return require $base['config_file'];}
function save_config(array $c):void{global $base;file_put_contents($base['config_file'],"<?php return ".var_export($c,true).";\n");}

$invalid=$env;$invalid['BOOKNEST_APP_URL']='http://public.example.test';[$code]=boot($invalid);expect($code!==0,'Public plain HTTP rejected');
$invalid=$env;$invalid['BOOKNEST_OWNER_PASSWORD']='';[$code]=boot($invalid);expect($code!==0,'Fresh install requires owner password');expect(!is_file($base['config_file']),'Failed setup does not create a configuration');
$invalid=$env;$invalid['BOOKNEST_OWNER_PASSWORD']='short';[$code]=boot($invalid);expect($code!==0,'Weak owner password rejected');
[$code,$out,$err]=boot($env);expect($code===0,'Docker bootstrap initializes workspace: '.$err);
$config=saved();expect(strlen($config['app_secret'])===64,'Application secret generated');expect($config['app_url']===$env['BOOKNEST_APP_URL'],'Public URL stored');expect((fileperms($base['config_file'])&0777)===0600,'Configuration permissions are private');
expect((int)$pdo->query('SELECT COUNT(*) FROM bk_users')->fetchColumn()===1,'One owner created');$owner=$pdo->query('SELECT * FROM bk_users')->fetch(PDO::FETCH_ASSOC);expect(password_verify($env['BOOKNEST_OWNER_PASSWORD'],$owner['password_hash']),'Owner password hashed correctly');
expect((int)$pdo->query('SELECT COUNT(*) FROM bk_hours')->fetchColumn()===7,'Seven opening-hour records initialized');expect((int)$pdo->query('SELECT active FROM bk_services')->fetchColumn()===0,'Initial service remains hidden');expect((int)$pdo->query("SELECT COUNT(*) FROM bk_settings WHERE setting_key='schedule_mutex'")->fetchColumn()===1,'Booking mutex initialized');
$env['BOOKNEST_OWNER_PASSWORD']='A changed environment password';$env['BOOKNEST_OWNER_EMAIL']='another@example.test';$env['BOOKNEST_BUSINESS_NAME']='Should not overwrite';[$code]=boot($env);expect($code===0,'Restart succeeds');expect(saved()['app_secret']===$config['app_secret'],'Restart preserves application secret');$owner2=$pdo->query('SELECT * FROM bk_users')->fetch(PDO::FETCH_ASSOC);expect($owner2['email']===$owner['email'] && $owner2['password_hash']===$owner['password_hash'],'Restart does not reset owner credentials');expect($pdo->query("SELECT setting_value FROM bk_settings WHERE setting_key='business_name'")->fetchColumn()==='Bootstrap Test Business','Restart preserves business settings');
$env['BOOKNEST_OWNER_PASSWORD']='';$env['BOOKNEST_OWNER_EMAIL']='';[$code]=boot($env);expect($code===0,'Owner setup password can be removed after initialization');
$env['BOOKNEST_RECOVERY_KEY']='A one use Docker recovery key 2026';[$code]=boot($env);expect($code===0 && saved()['recovery_key']===$env['BOOKNEST_RECOVERY_KEY'],'Docker environment enables owner recovery');
$c=saved();$c['recovery_key']='';save_config($c);[$code]=boot($env);expect($code===0 && saved()['recovery_key']==='','Used recovery key does not reactivate on restart');
$env['BOOKNEST_RECOVERY_KEY']='A second Docker recovery key 2026';[$code]=boot($env);expect($code===0 && saved()['recovery_key']===$env['BOOKNEST_RECOVERY_KEY'],'A new recovery key can be enabled');
$env['BOOKNEST_RECOVERY_KEY']='';[$code]=boot($env);expect($code===0 && saved()['recovery_key']==='','Clearing environment disables recovery');
putenv('BOOKNEST_CONFIG_FILE='.$base['config_file']);expect(config_file_path()===$base['config_file'],'Runtime resolves external configuration path');putenv('BOOKNEST_CONFIG_FILE');expect(config_file_path()===$source.'/app/config.php','Ordinary PHP configuration path still supported');
expect((int)$pdo->query('SELECT COUNT(*) FROM bk_users')->fetchColumn()===1 && (int)$pdo->query('SELECT COUNT(*) FROM bk_services')->fetchColumn()===1,'Repeated boots do not duplicate records');
echo 'TOTAL '.$count." Docker bootstrap checks passed.\n";
