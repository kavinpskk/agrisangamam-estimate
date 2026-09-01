<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

if (!logged_in()) {
    header('Location: index.php?page=login');
    exit;
}

function backup_filename(string $suffix): string {
    return 'SGAS-'.date('Y-m-d-His').'-'.$suffix;
}

function sql_value(PDO $pdo, mixed $value): string {
    if ($value === null) return 'NULL';
    return $pdo->quote((string)$value);
}

function backup_authorized(): bool {
    return (int)($_SESSION['backup_authorized_until'] ?? 0) >= time();
}

function require_backup_authorization(): void {
    if (!backup_authorized()) {
        http_response_code(403);
        exit('Backup authorization expired. Return to Backup & Export and verify the administrator password again.');
    }
}

function download_database_backup(PDO $pdo): never {
    require_backup_authorization();
    $tables = ['settings','categories','products','customers','bills','bill_items','payments'];
    $sql = "-- SGAS database backup\n";
    $sql .= "-- Generated: ".date('c')."\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) continue;
        $quoted = '`'.str_replace('`', '``', (string)$table).'`';
        $create = $pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM);
        if (!$create) continue;
        $sql .= "DROP TABLE IF EXISTS ".$quoted.";\n";
        $sql .= $create[1].";\n\n";

        $rows = $pdo->query('SELECT * FROM '.$quoted);
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_map(
                static fn(string $column): string => '`'.str_replace('`', '``', $column).'`',
                array_keys($row)
            );
            $values = array_map(static fn(mixed $value): string => sql_value($pdo, $value), array_values($row));
            $sql .= 'INSERT INTO '.$quoted.' ('.implode(',', $columns).') VALUES ('.implode(',', $values).");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $compressed = function_exists('gzencode') ? gzencode($sql, 9) : false;
    if ($compressed !== false) {
        $filename = backup_filename('database.sql.gz');
        header('Cache-Control: no-store, private');
        header('Content-Type: application/gzip');
        header('Content-Length: '.strlen($compressed));
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('X-Content-Type-Options: nosniff');
        echo $compressed;
        exit;
    }

    $filename = backup_filename('database.sql');
    header('Cache-Control: no-store, private');
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Length: '.strlen($sql));
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('X-Content-Type-Options: nosniff');
    echo $sql;
    exit;
}

function download_csv(PDO $pdo, string $type): never {
    require_backup_authorization();
    $exports = [
        'customers' => ['customers.csv', 'SELECT id,name,mobile,address,opening_balance,created_at FROM customers ORDER BY name'],
        'products' => ['products.csv', 'SELECT p.id,c.name category,p.english_name,p.tamil_name,p.unit,p.default_rate,p.active,p.created_at FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.english_name'],
        'bills' => ['bills.csv', 'SELECT b.id,b.bill_no,c.name customer,c.mobile,b.subtotal,b.amount_received,b.closing_balance,b.status,b.created_at,b.updated_at FROM bills b JOIN customers c ON c.id=b.customer_id ORDER BY b.id DESC'],
        'payments' => ['payments.csv', 'SELECT p.id,c.name customer,c.mobile,p.amount,p.note,p.created_at FROM payments p JOIN customers c ON c.id=p.customer_id ORDER BY p.id DESC'],
    ];
    if (!isset($exports[$type])) {
        http_response_code(400);
        exit('Invalid export type.');
    }

    [$suffix, $sql] = $exports[$type];
    $rows = $pdo->query($sql);
    header('Cache-Control: no-store, private');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.backup_filename($suffix).'"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    $first = $rows->fetch(PDO::FETCH_ASSOC);
    if ($first) {
        fputcsv($out, array_keys($first));
        fputcsv($out, array_values($first));
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

$backupError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'unlock') {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $hash = (string)$stmt->fetchColumn();
        if ($hash !== '' && password_verify((string)($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['backup_authorized_until'] = time() + 300;
            header('Location: backup.php');
            exit;
        }
        $backupError = 'Administrator password is incorrect.';
    }
    if ($action === 'database') download_database_backup($pdo);
    if ($action === 'csv') download_csv($pdo, (string)($_POST['type'] ?? ''));
    http_response_code(400);
    exit('Invalid backup action.');
}

$counts = [
    'Customers' => (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
    'Products' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'Bills' => (int)$pdo->query('SELECT COUNT(*) FROM bills')->fetchColumn(),
    'Payments' => (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
];
$dbSize = 0;
try {
    $sizeStmt = $pdo->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=?');
    $sizeStmt->execute([$config['db_name']]);
    $dbSize = (int)$sizeStmt->fetchColumn();
} catch (Throwable $e) {
    $dbSize = 0;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b432a">
<title>Backup & Export — SGAS</title>
<link rel="stylesheet" href="assets/app.css?v=20260831-36">
<link rel="stylesheet" href="assets/sidebar.css?v=20260831-2">
<link rel="stylesheet" href="assets/backup.css?v=20260901-1">
</head>
<body class="page-backup">
<button type="button" class="menu-toggle no-print" aria-label="Open menu" aria-expanded="false">☰</button>
<nav class="no-print">
<strong>SGAS</strong>
<a href="index.php">Dashboard</a>
<a href="index.php?page=new_bill">New Bill</a>
<a href="index.php?page=bills">Bills</a>
<a href="index.php?page=customers">Customers</a>
<a href="index.php?page=ledger">Ledger</a>
<a href="index.php?page=payments">Payments</a>
<a href="index.php?page=products">Products</a>
<a href="index.php?page=categories">Categories</a>
<a href="index.php?page=whatsapp_reminders">WhatsApp</a>
<a href="index.php?page=reports">Reports</a>
<a class="active" href="backup.php">Backup</a>
<a href="index.php?page=logout">Logout</a>
</nav>
<main class="wrap backup-page">
<?php if ($backupError !== ''): ?><div class="notice error"><?=e($backupError)?></div><?php endif; ?>
<header class="backup-heading">
<div><p class="eyebrow">Data protection</p><h1>Backup & Export</h1><p>Download a secure copy of your SGAS business data before updates and at regular intervals.</p></div>
<span class="backup-status">Database connected</span>
</header>

<section class="backup-stats">
<?php foreach ($counts as $label => $value): ?>
<div class="card"><small><?=e($label)?></small><strong><?=number_format($value)?></strong></div>
<?php endforeach; ?>
<div class="card"><small>Database size</small><strong><?=$dbSize ? e(number_format($dbSize/1048576, 2)).' MB' : 'Available'?></strong></div>
</section>

<?php if (!backup_authorized()): ?>
<section class="card backup-unlock">
<div><span class="backup-icon">LOCK</span><div><h2>Verify administrator password</h2><p>Customer and billing data is protected. Re-enter your password to enable downloads for five minutes.</p></div></div>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="unlock">
<label for="backup-password">Administrator password</label>
<div><input id="backup-password" type="password" name="password" required autocomplete="current-password"><button type="submit">Unlock Backups</button></div>
</form>
</section>
<?php else: ?>
<section class="card backup-primary">
<div><span class="backup-icon">DB</span><div><h2>Complete business-data backup</h2><p>Includes customers, products, categories, bills, bill items, payments and settings. Administrator password records are excluded for security.</p></div></div>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="database">
<button type="submit">Download Database Backup</button>
</form>
</section>

<section class="backup-grid">
<div class="card">
<h2>Business data exports</h2>
<p>CSV files open in Microsoft Excel and preserve Tamil text.</p>
<div class="export-list">
<?php foreach (['customers'=>'Customers','products'=>'Products','bills'=>'Bills','payments'=>'Payments'] as $type=>$label): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="csv">
<input type="hidden" name="type" value="<?=e($type)?>">
<span><b><?=e($label)?></b><small>Download <?=e(strtolower($label))?> data</small></span>
<button type="submit" class="secondary">Export CSV</button>
</form>
<?php endforeach; ?>
</div>
</div>

<div class="card backup-guide">
<h2>Recommended schedule</h2>
<ul>
<li><b>Daily:</b> Download the database backup.</li>
<li><b>Before updates:</b> Create a fresh backup first.</li>
<li><b>Weekly:</b> Copy the latest backup to Google Drive.</li>
<li><b>Monthly:</b> Keep one permanent archive.</li>
</ul>
<div class="safety-note"><b>Restore safety</b><p>Restoration is intentionally not automatic. Import the SQL backup through phpMyAdmin only after confirming the target database and taking a fresh safety backup.</p></div>
</div>
</section>
<?php endif; ?>
</main>
<script src="assets/app.js?v=20260901-49"></script>
</body>
</html>
