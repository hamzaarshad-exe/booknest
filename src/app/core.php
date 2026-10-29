<?php
declare(strict_types=1);

final class ValidationError extends RuntimeException {}

function boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('display_errors', '0');
    session_name('booknest_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443'),'httponly'=>true,'samesite'=>'Lax']);
    if(!session_start()) {
        error_log('BookNest: PHP session storage is unavailable. Check session.save_path and permissions.');
        http_response_code(503);
        exit('This booking page is temporarily unavailable. Please contact the business.');
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, private');
}

function config_file_path(): string {
    $path=getenv('BOOKNEST_CONFIG_FILE');
    return is_string($path) && $path!=='' ? $path : __DIR__.'/config.php';
}
function config(): array { return $GLOBALS['booknest_config']; }
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $c = config();
        $pdo = new PDO('mysql:host='.$c['db_host'].';port='.($c['db_port'] ?? 3306).';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function rows(string $sql, array $params=[]): array { $s=db()->prepare($sql);$s->execute($params);return $s->fetchAll(); }
function one(string $sql, array $params=[]): ?array { return rows($sql,$params)[0] ?? null; }
function run(string $sql, array $params=[]): int { $s=db()->prepare($sql);$s->execute($params);return $s->rowCount(); }
function setting(string $key, string $default=''): string {
    if (!isset($GLOBALS['booknest_settings'])) $GLOBALS['booknest_settings']=array_column(rows('SELECT * FROM bk_settings'),'setting_value','setting_key');
    return $GLOBALS['booknest_settings'][$key] ?? $default;
}
function set_setting(string $key, string $value): void { run('INSERT INTO bk_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,$value]);unset($GLOBALS['booknest_settings']); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function url(string $page='book', array $params=[]): string { return 'index.php?'.http_build_query(['page'=>$page]+$params); }
function absolute_url(string $page, array $params=[]): string { return rtrim(config()['app_url'],'/').'/'.url($page,$params); }
function redirect(string $page, array $params=[]): never { header('Location: '.url($page,$params),true,303);exit; }
function flash(string $message, string $kind='success'): void { $_SESSION['flashes'][]=['message'=>$message,'kind'=>$kind]; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf()).'">'; }
function verify_csrf(): void { if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf(),$_POST['csrf'])) throw new ValidationError('This form has expired. Refresh the page and try again.'); }
function text_input(array $data, string $key, int $max, bool $required=true): string {
    $v=$data[$key] ?? '';
    if (!is_string($v)) throw new ValidationError('Please check the '.$key.' field.');
    $v=trim($v);
    if (($required && $v==='') || strlen($v)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v)) throw new ValidationError('Please enter a valid '.str_replace('_',' ',$key).' (maximum '.$max.' characters).');
    return $v;
}
function int_input(array $data, string $key, int $min, int $max): int {
    $v=$data[$key] ?? null;
    if (!is_scalar($v) || filter_var($v,FILTER_VALIDATE_INT)===false || (int)$v<$min || (int)$v>$max) throw new ValidationError('Please check '.str_replace('_',' ',$key).'.');
    return (int)$v;
}
function password_input(array $data, string $key, int $min=1): string {
    $v=$data[$key] ?? '';
    if(!is_string($v) || strlen($v)<$min || strlen($v)>72)throw new ValidationError('Passwords must be '.$min.'–72 characters long.');
    return $v;
}
function money_minor(string $amount): int {
    if (!preg_match('/^(0|[1-9]\d{0,6})(?:\.(\d{1,2}))?$/',$amount,$m)) throw new ValidationError('Enter an amount from 0 to 9,999,999.99 with up to two decimal places.');
    return ((int)$m[1])*100+(int)str_pad($m[2] ?? '',2,'0');
}
function money(int $minor, ?string $currency=null): string { return ($currency ?? setting('currency','PKR')).' '.number_format($minor/100,2); }
function raw_amount(int $minor): string { return number_format($minor/100,2,'.',''); }
function tz(): DateTimeZone { return new DateTimeZone(setting('timezone','Asia/Karachi')); }
function now_utc(): DateTimeImmutable { return new DateTimeImmutable('now',new DateTimeZone('UTC')); }
function local_now(): DateTimeImmutable { return now_utc()->setTimezone(tz()); }
function utc_string(DateTimeImmutable $d): string { return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
function local_date(string $utc, string $format='D, j M · g:i a'): string { return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(tz())->format($format); }
function parse_local(string $date, string $time): DateTimeImmutable {
    $value=$date.' '.$time;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value,tz());
    if (!$d || $d->format('Y-m-d H:i')!==$value) throw new ValidationError('Choose a valid date and time.');
    // Ambiguous daylight-saving times are omitted to keep each displayed slot unique.
    return $d;
}
function user(): ?array {
    static $loaded=false,$u=null;
    if (!$loaded) {
        $loaded=true;
        if (isset($_SESSION['user_id']) && time()-(int)($_SESSION['last_seen'] ?? 0)<14400) {
            $u=one('SELECT id,name,email,session_version FROM bk_users WHERE id=?',[$_SESSION['user_id']]);
            if (!$u || (int)$u['session_version']!==(int)($_SESSION['session_version'] ?? 0)) $u=null;
            else $_SESSION['last_seen']=time();
        }
    }
    return $u;
}
function require_user(): array { if (!$u=user()) { flash('Sign in to open your dashboard.','info');redirect('login'); }return $u; }
function audit(string $event, string $detail): void { run('INSERT INTO bk_audit (actor_id,event,detail,created_at) VALUES (?,?,?,UTC_TIMESTAMP())',[user()['id'] ?? null,$event,substr($detail,0,255)]); }
function rate_limit(string $purpose, int $max, int $seconds=900, string $extra=''): void {
    $window=(int)(floor(time()/$seconds)*$seconds);
    $key=hash('sha256',($_SERVER['REMOTE_ADDR'] ?? 'cli').'|'.$purpose.'|'.$extra.'|'.$window);
    run('INSERT INTO bk_rate_limits (key_hash,window_started,hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits=hits+1',[$key,gmdate('Y-m-d H:i:s',$window)]);
    if ((int)(one('SELECT hits FROM bk_rate_limits WHERE key_hash=?',[$key])['hits'] ?? 0)>$max) throw new ValidationError('Too many attempts. Please try again in a little while.');
    if (random_int(1,50)===1) run('DELETE FROM bk_rate_limits WHERE window_started < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) LIMIT 1000');
}
function phone_input(string $phone): string {
    if (!preg_match('/^\+?[0-9 ()\-.]{7,30}$/',$phone) || strlen(preg_replace('/\D/','',$phone))<7 || strlen(preg_replace('/\D/','',$phone))>15) throw new ValidationError('Enter a valid phone number, including your country code.');
    return $phone;
}
function valid_currency(string $currency): string {
    if (!in_array($currency,['PKR','USD','GBP','EUR','AED','SAR','INR','BDT','CAD','AUD'],true)) throw new ValidationError('Choose a supported currency.');
    return $currency;
}
function payment_total(int $id): int { return (int)(one("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -CAST(amount_minor AS SIGNED) ELSE amount_minor END),0) AS paid FROM bk_payments WHERE appointment_id=?",[$id])['paid'] ?? 0); }
function status_label(string $status): string { return ['pending'=>'Pending','confirmed'=>'Confirmed','completed'=>'Completed','cancelled'=>'Cancelled','no_show'=>'No-show'][$status] ?? $status; }
function status_badge(string $status): string { return '<span class="badge badge-'.e($status).'">'.e(status_label($status)).'</span>'; }
function short_name(string $name): string {
    $parts=preg_split('/\s+/u',trim($name));$out='';
    foreach (array_slice($parts ?: [],0,2) as $part) { preg_match('/^./u',$part,$m);$out.=$m[0] ?? ''; }
    return strtoupper($out ?: 'B');
}
function icon(string $name, string $class=''): string {
    $paths=[
     'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2m4 0h2m-8 3h2"/>',
     'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
     'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
     'people'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 5"/>',
     'service'=>'<path d="m12 3 2.8 5.7L21 9.6l-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
     'settings'=>'<path d="m9 3-.8 2.5-2.6.6-1.8 3 1.3 2.4-.4 2.6 2.2 2.7 2.6-.1L12 18l2.5-1.3 2.6.1 2.2-2.7-.4-2.6 1.3-2.4-1.8-3-2.6-.6L15 3H9Z"/><circle cx="12" cy="10.5" r="3"/>',
     'arrow'=>'<path d="M5 12h14m-5-5 5 5-5 5"/>',
     'check'=>'<path d="m5 12 4 4L19 6"/>',
     'plus'=>'<path d="M12 5v14M5 12h14"/>',
     'money'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 9h1m10 6h1"/>',
     'external'=>'<path d="M14 3h7v7m0-7L10 14m0-11H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5"/>',
     'logout'=>'<path d="M9 5H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4m7-14 5 7-5 7M8 12h13"/>',
     'location'=>'<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
     'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>',
     'copy'=>'<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
     'chevron'=>'<path d="m9 5 7 7-7 7"/>',
     'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
     'mail'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
     'spark'=>'<path d="m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4L12 3Z"/>',
    ];
    return '<svg class="icon '.e($class).'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['calendar']).'</svg>';
}
