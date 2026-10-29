<?php
declare(strict_types=1);
require __DIR__.'/app/core.php';boot_session();
if(!is_file(config_file_path())){http_response_code(404);exit('Not available.');}
$GLOBALS['booknest_config']=require config_file_path();$key=config()['recovery_key'] ?? '';
if(!is_string($key) || strlen($key)<32){http_response_code(404);exit('Recovery is disabled. Follow the recovery steps in the installation guide.');}
require __DIR__.'/app/layout.php';$error='';$done=false;
if($_SERVER['REQUEST_METHOD']==='POST')try{
    verify_csrf();rate_limit('recovery',6,3600);
    if(!hash_equals($key,text_input($_POST,'recovery_key',200)))throw new ValidationError('Recovery details are incorrect.');
    $email=strtolower(text_input($_POST,'email',190));$password=password_input($_POST,'password',12);$u=one('SELECT * FROM bk_users WHERE email=?',[$email]);if(!$u)throw new ValidationError('Recovery details are incorrect.');
    run('UPDATE bk_users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
    $c=config();$c['recovery_key']='';$tmp=dirname(config_file_path()).'/config-staging.php';$written=file_put_contents($tmp,"<?php\nreturn ".var_export($c,true).";\n",LOCK_EX);if($written!==false){@chmod($tmp,0600);$written=rename($tmp,config_file_path());}
    if(!$written)throw new ValidationError('Password updated. Remove recovery_key from app/config.php manually before signing in.');
    $_SESSION=[];session_regenerate_id(true);$done=true;
}catch(ValidationError $e){$error=$e->getMessage();}catch(Throwable $e){error_log('BookNest recovery failed: '.$e->getMessage());$error='Recovery could not finish. Check the error log and your configuration.';}
head('Owner recovery','auth-body');?><main id="main" class="auth-card"><p class="eyebrow">OWNER RECOVERY</p><h1>Back to your business.</h1><?php if($done):?><p class="muted">Your password was updated and the recovery key was removed.</p><a class="button" href="<?=e(url('login'))?>">Sign in</a><?php else:if($error):?><div class="notice notice-error"><?=e($error)?></div><?php endif;?><form method="post" class="stack"><?=csrf_field()?><?php field('recovery_key','Recovery key');field('email','Owner email','','email');?><label class="field"><span>New password</span><input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><button class="button">Reset owner password</button></form><?php endif;?></main><?php foot();
