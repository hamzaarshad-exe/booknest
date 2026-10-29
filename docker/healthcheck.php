<?php
declare(strict_types=1);

try {
    $file=getenv('BOOKNEST_CONFIG_FILE') ?: '/var/lib/booknest/config.php';
    if(!is_file($file)) exit(1);
    $c=require $file;
    $pdo=new PDO('mysql:host='.$c['db_host'].';port='.$c['db_port'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);
    $pdo->query('SELECT 1')->fetchColumn();
    $connection=@fsockopen('127.0.0.1',80,$code,$message,3);
    if(!$connection) exit(1);
    fclose($connection);
    exit(0);
} catch(Throwable) { exit(1); }
