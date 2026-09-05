<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SGAS_BACKUP_CRON', true);
require __DIR__.'/backup.php';
