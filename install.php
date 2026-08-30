<?php
$configFile=__DIR__.'/config.php';
if(!file_exists($configFile)) exit('First copy config.example.php to config.php and enter database details.');
$config=require $configFile;
try{$pdo=new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Throwable $e){exit('Database connection failed.');}
$count=0;try{$count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();}catch(Throwable $e){exit('Import schema.sql in phpMyAdmin first.');}
$message='';
if($_SERVER['REQUEST_METHOD']==='POST' && $count===0){$u=trim($_POST['username']??'');$p=$_POST['password']??'';if(strlen($u)<3||strlen($p)<8){$message='Use a username of 3+ characters and password of 8+ characters.';}else{$s=$pdo->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)');$s->execute([$u,password_hash($p,PASSWORD_DEFAULT)]);$message='Administrator created. Delete install.php now, then open index.php.';$count=1;}}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>SGAS Setup</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="wrap login"><div class="card"><h1>SGAS Setup</h1><?php if($message):?><div class="notice"><?=htmlspecialchars($message)?></div><?php endif;?><?php if($count):?><p>An administrator already exists. For security, delete <b>install.php</b>.</p><a class="btn" href="index.php">Open SGAS</a><?php else:?><form method="post"><p><label>Administrator username</label><input name="username" required></p><p><label>Password</label><input type="password" name="password" minlength="8" required></p><button>Create administrator</button></form><?php endif;?></div></main></body></html>
