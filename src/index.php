<?php
declare(strict_types=1);
require __DIR__.'/app/core.php';
boot_session();
if(!is_file(config_file_path())) {
    http_response_code(503);?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BookNest setup</title><link rel="stylesheet" href="assets/app.css"><body class="auth-body"><main class="auth-card"><span class="eyebrow">BOOKNEST</span><h1>A fresh start.</h1><p class="muted">This booking page is being set up. If you own this website, open the private installation link in your setup guide.</p></main></body></html><?php exit;
}
$GLOBALS['booknest_config']=require config_file_path();
require __DIR__.'/app/booking.php';
require __DIR__.'/app/actions.php';
require __DIR__.'/app/layout.php';
require __DIR__.'/app/public.php';
require __DIR__.'/app/admin.php';
try {
    if($_SERVER['REQUEST_METHOD']==='POST') handle_post();
    $page=is_string($_GET['page'] ?? null)?$_GET['page']:'book';
    if($page==='book')view_book();
    elseif($page==='manage')view_manage();
    elseif($page==='move')view_move();
    elseif($page==='calendar')download_calendar();
    elseif($page==='login')view_login();
    elseif(in_array($page,['dashboard','appointments','appointment','services','staff','schedule','settings','account','export'],true)){require_user();match($page){'dashboard'=>view_dashboard(),'appointments'=>view_appointments(),'appointment'=>view_appointment(),'services'=>view_services(),'staff'=>view_staff(),'schedule'=>view_schedule(),'settings'=>view_settings(),'account'=>view_account(),'export'=>download_export()};}
    else {http_response_code(404);head('Page not found');public_header();echo '<main id="main" class="narrow-main">';empty_state('Page not found','Return to the booking page to choose your next appointment.');echo '<a class="button" href="'.e(url()).'">Book an appointment</a></main>';public_footer();foot();}
}catch(Throwable $err){
    error_log('BookNest request failed: '.$err->getMessage());
    if(!headers_sent())http_response_code(503);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporarily unavailable</title><link rel="stylesheet" href="assets/app.css"><body class="auth-body"><main class="auth-card"><h1>We’ll be right back.</h1><p>This page is temporarily unavailable. Please try again in a moment or contact the business directly.</p></main></body></html>';
}
