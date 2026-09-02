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

function build_database_backup(PDO $pdo): array {
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

        $rowSql = $table === 'settings'
            ? "SELECT * FROM settings WHERE setting_key NOT IN ('google_drive_backup_url','google_drive_backup_token')"
            : 'SELECT * FROM '.$quoted;
        $rows = $pdo->query($rowSql);
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
        return ['filename'=>backup_filename('database.sql.gz'),'content'=>$compressed,'mime'=>'application/gzip'];
    }
    return ['filename'=>backup_filename('database.sql'),'content'=>$sql,'mime'=>'application/sql'];
}

function download_database_backup(PDO $pdo): never {
    $backup = build_database_backup($pdo);
    header('Cache-Control: no-store, private');
    header('Content-Type: '.$backup['mime']);
    header('Content-Length: '.strlen($backup['content']));
    header('Content-Disposition: attachment; filename="'.$backup['filename'].'"');
    header('X-Content-Type-Options: nosniff');
    echo $backup['content'];
    exit;
}

function save_drive_settings(PDO $pdo, string $url, string $token): void {
    $url = trim($url);
    $token = trim($token);
    if (!preg_match('#^https://script\\.google\\.com/macros/s/[A-Za-z0-9_-]+/exec$#', $url)) {
        throw new RuntimeException('Enter the valid Google Apps Script web-app URL.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('The private Drive backup token is invalid.');
    }
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute(['google_drive_backup_url',$url]);
    $stmt->execute(['google_drive_backup_token',$token]);
}

function upload_database_backup(PDO $pdo): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Server cURL support is required for Google Drive upload.');
    $url = setting($pdo, 'google_drive_backup_url');
    $token = setting($pdo, 'google_drive_backup_token');
    if ($url === '' || $token === '') throw new RuntimeException('Google Drive backup is not configured.');
    $backup = build_database_backup($pdo);
    $payload = json_encode([
        'token'=>$token,
        'filename'=>$backup['filename'],
        'content_base64'=>base64_encode($backup['content']),
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('Unable to prepare the Drive backup.');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>5,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>90,
        CURLOPT_SSL_VERIFYPEER=>true,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Google Drive upload failed'.($curlError !== ''?': '.$curlError:'.'));
    }
    $result = json_decode((string)$response, true);
    if (!is_array($result) || empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? 'Google Drive did not confirm the backup.'));
    }
    return $result;
}

function download_csv(PDO $pdo, string $type): never {
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

function import_sgas_sql(PDO $pdo, array $upload): array {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Select the converted SGAS SQL file and try again.');
    }
    $size = (int)($upload['size'] ?? 0);
    if ($size < 100 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('The import file must be smaller than 5 MB.');
    }
    $name = (string)($upload['name'] ?? '');
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'sql') {
        throw new RuntimeException('Only the converted SGAS .sql file is accepted.');
    }
    $content = file_get_contents((string)$upload['tmp_name']);
    if ($content === false || !str_starts_with($content, "-- SGAS offline-to-online import\n")) {
        throw new RuntimeException('This is not a valid SGAS offline-data import file.');
    }
    if (!str_contains($content, '-- Financial year: 1 April 2026 to 31 March 2027')) {
        throw new RuntimeException('The selected file is not the 2026-27 financial-year import.');
    }

    $allowedTables = ['bill_items','payments','bills','products','categories','customers','settings'];
    $statements = [];
    $expected = array_fill_keys(['bill_items','payments','bills','products','categories','customers'], 0);
    $expectedClosing = null;
    foreach (preg_split('/\R/u', $content) ?: [] as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (str_starts_with($line, '--')) {
            if (preg_match('/^-- Closing outstanding: ([0-9]+(?:\.[0-9]{1,2})?)$/', $line, $match)) {
                $expectedClosing = (float)$match[1];
            }
            continue;
        }
        if (in_array($line, ['SET NAMES utf8mb4;','SET FOREIGN_KEY_CHECKS=0;','SET FOREIGN_KEY_CHECKS=1;','START TRANSACTION;','COMMIT;'], true)) continue;
        if (preg_match('/^ALTER TABLE (bill_items|payments|bills|products|categories|customers) AUTO_INCREMENT=1;$/', $line)) continue;
        if (preg_match('/^DELETE FROM (bill_items|payments|bills|products|categories|customers);$/', $line, $match)) {
            $statements[] = $line;
            continue;
        }
        if (preg_match('/^INSERT INTO (bill_items|payments|bills|products|categories|customers|settings)\s/i', $line, $match)) {
            $table = strtolower($match[1]);
            if (!in_array($table, $allowedTables, true)) throw new RuntimeException('Unsupported import table.');
            $statements[] = $line;
            if (isset($expected[$table])) $expected[$table]++;
            continue;
        }
        throw new RuntimeException('Unsupported statement on import line '.($lineNumber + 1).'.');
    }
    if ($expected['customers'] === 0 || $expected['products'] === 0 || $expected['bills'] === 0 || $expectedClosing === null) {
        throw new RuntimeException('The import file is incomplete. Nothing was changed.');
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $pdo->beginTransaction();
        foreach ($statements as $statement) $pdo->exec($statement);
        foreach ($expected as $table => $count) {
            $actual = (int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
            if ($actual !== $count) throw new RuntimeException('Imported '.$table.' count does not match the file.');
        }
        $closing = (float)$pdo->query(
            "SELECT ROUND((SELECT COALESCE(SUM(opening_balance),0) FROM customers) + "
            ."(SELECT COALESCE(SUM(subtotal-amount_received),0) FROM bills WHERE status='active') - "
            ."(SELECT COALESCE(SUM(amount),0) FROM payments), 2)"
        )->fetchColumn();
        if (abs($closing - $expectedClosing) > 0.01) {
            throw new RuntimeException('Closing outstanding validation failed. Nothing was imported.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    return $expected + ['closing_balance'=>$expectedClosing];
}

$backupError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'database') download_database_backup($pdo);
        if ($action === 'csv') download_csv($pdo, (string)($_POST['type'] ?? ''));
        if ($action === 'configure_drive') {
            save_drive_settings($pdo, (string)($_POST['drive_url'] ?? ''), (string)($_POST['drive_token'] ?? ''));
            $_SESSION['backup_success'] = 'Google Drive backup connected successfully.';
            header('Location: backup.php');
            exit;
        }
        if ($action === 'drive_backup') {
            $result = upload_database_backup($pdo);
            $_SESSION['backup_success'] = 'Backup uploaded to Google Drive: '.(string)$result['filename'];
            header('Location: backup.php');
            exit;
        }
        if ($action === 'import_offline') {
            if (($_POST['confirm_import'] ?? '') !== 'yes') throw new RuntimeException('Confirm that you created a fresh backup before importing.');
            $result = import_sgas_sql($pdo, $_FILES['import_file'] ?? []);
            $_SESSION['backup_success'] = 'Offline data imported successfully: '.number_format($result['customers']).' customers, '.number_format($result['products']).' products, '.number_format($result['bills']).' bills and '.number_format($result['payments']).' payments. Closing outstanding ₹'.number_format($result['closing_balance'], 2).'.';
            header('Location: backup.php');
            exit;
        }
    } catch (Throwable $e) {
        $backupError = $e->getMessage();
    }
    if ($backupError === '') {
        http_response_code(400);
        exit('Invalid backup action.');
    }
}

$backupSuccess = (string)($_SESSION['backup_success'] ?? '');
unset($_SESSION['backup_success']);
$driveUrl = setting($pdo, 'google_drive_backup_url');
$driveConnected = $driveUrl !== '' && setting($pdo, 'google_drive_backup_token') !== '';

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
<link rel="stylesheet" href="assets/backup.css?v=20260902-3">
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
<a href="index.php?page=settings">Settings</a>
<a class="active" href="backup.php">Backup</a>
<a href="index.php?page=logout">Logout</a>
</nav>
<main class="wrap backup-page">
<?php if ($backupError !== ''): ?><div class="notice error"><?=e($backupError)?></div><?php endif; ?>
<?php if ($backupSuccess !== ''): ?><div class="notice"><?=e($backupSuccess)?></div><?php endif; ?>
<header class="backup-heading">
<div><p class="eyebrow">Data protection</p><h1>Backup & Export</h1><p>Download a secure copy of your SGAS business data before updates and at regular intervals.</p></div>
<div class="backup-statuses"><span class="backup-status">Database connected</span><?php if ($driveConnected): ?><span class="backup-status">Google Drive connected</span><?php endif; ?></div>
</header>

<section class="backup-stats">
<?php foreach ($counts as $label => $value): ?>
<div class="card"><small><?=e($label)?></small><strong><?=number_format($value)?></strong></div>
<?php endforeach; ?>
<div class="card"><small>Database size</small><strong><?=$dbSize ? e(number_format($dbSize/1048576, 2)).' MB' : 'Available'?></strong></div>
</section>

<section class="card backup-primary">
<div><span class="backup-icon">DB</span><div><h2>Complete business-data backup</h2><p>Includes customers, products, categories, bills, bill items, payments and settings. Administrator password records are excluded for security.</p></div></div>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="database">
<button type="submit">Download Database Backup</button>
</form>
</section>

<section class="card drive-card">
<div><span class="backup-icon">GD</span><div><h2>Google Drive backup</h2><p><?= $driveConnected ? 'Send the current database backup directly to the SGAS Backups folder.' : 'Connect the secure Apps Script endpoint to enable one-click Drive backups.' ?></p></div></div>
<?php if ($driveConnected): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="drive_backup">
<button type="submit">Backup to Google Drive</button>
</form>
<?php else: ?>
<form method="post" class="drive-connect-form" autocomplete="off">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="configure_drive">
<label>Apps Script web-app URL<input type="url" name="drive_url" required placeholder="https://script.google.com/macros/s/.../exec"></label>
<label>Private backup token<input type="password" name="drive_token" required minlength="64" maxlength="64" autocomplete="new-password"></label>
<button type="submit">Connect Google Drive</button>
</form>
<?php endif; ?>
</section>

<section class="card import-card">
<div class="import-copy"><span class="backup-icon">IN</span><div><h2>Import offline financial-year data</h2><p>Upload the converted SGAS 2026-27 SQL file. This replaces existing categories, products, customers, bills and payments after validating the complete file and closing balance.</p></div></div>
<form method="post" enctype="multipart/form-data" class="import-form" onsubmit="return confirm('Importing will replace the current business data. Continue?')">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="import_offline">
<label class="file-field"><span>Converted SGAS SQL file</span><input type="file" name="import_file" accept=".sql,application/sql,text/plain" required></label>
<label class="import-confirm"><input type="checkbox" name="confirm_import" value="yes" required><span>I downloaded a fresh database backup and understand that existing business data will be replaced.</span></label>
<button type="submit" class="import-button">Import Offline Data</button>
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
<li><b>Daily:</b> Click Backup to Google Drive.</li>
<li><b>Before updates:</b> Create a fresh backup first.</li>
<li><b>Weekly:</b> Confirm the latest file appears in Google Drive.</li>
<li><b>Monthly:</b> Keep one permanent archive.</li>
</ul>
<div class="safety-note"><b>Import safety</b><p>Always create a fresh backup before importing offline data. The importer validates record totals and the final customer outstanding balance before committing changes.</p></div>
</div>
</section>
</main>
<script src="assets/app.js?v=20260901-49"></script>
</body>
</html>
