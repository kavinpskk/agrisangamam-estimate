<?php
declare(strict_types=1);
session_start();
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) { http_response_code(503); exit('Setup required: copy config.example.php to config.php and enter the database password.'); }
$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Asia/Kolkata');
try {
    $pdo = new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4', $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) { http_response_code(503); exit('Database connection failed. Check config.php.'); }
function e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float)$v, 2); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Session expired. Please go back and try again.'); } }
function logged_in(): bool { return !empty($_SESSION['user_id']); }
function require_login(): void { if (!logged_in()) { header('Location: ?page=login'); exit; } }
function redirect(string $to): never { header('Location: '.$to); exit; }
function flash(string $msg, string $type='ok'): void { $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function setting(PDO $pdo, string $key, string $fallback=''): string { $s=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?'); $s->execute([$key]); return (string)($s->fetchColumn() ?: $fallback); }
function whatsapp_number(?string $mobile): string {
    $digits=preg_replace('/\D/','',$mobile??'');
    if(strlen($digits)===10) $digits='91'.$digits;
    return strlen($digits)===12&&str_starts_with($digits,'91')?$digits:'';
}
function normalize_mobile(?string $mobile): string {
    $raw=trim($mobile??'');
    if($raw==='') return '';
    $number=whatsapp_number($raw);
    if($number==='') throw new RuntimeException('Enter a valid 10-digit Indian WhatsApp number.');
    return $number;
}
function supports_bill_balance_snapshot(PDO $pdo): bool {
    static $supported=null;
    if($supported===null){$s=$pdo->query("SHOW COLUMNS FROM bills LIKE 'closing_balance'");$supported=(bool)$s->fetch();}
    return $supported;
}
function customer_balance(PDO $pdo, int $id, ?int $excludeBill=null): float {
    $s=$pdo->prepare('SELECT opening_balance FROM customers WHERE id=?'); $s->execute([$id]); $bal=(float)$s->fetchColumn();
    $sql="SELECT COALESCE(SUM(subtotal-amount_received),0) FROM bills WHERE customer_id=? AND status='active'".($excludeBill?' AND id<>?':'');
    $s=$pdo->prepare($sql); $s->execute($excludeBill?[$id,$excludeBill]:[$id]); $bal+=(float)$s->fetchColumn();
    $s=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id=?'); $s->execute([$id]);
    return $bal-(float)$s->fetchColumn();
}
