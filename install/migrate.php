<?php
// CLI only: php install/migrate.php — applies install/migrations/*.sql once each, in name order.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../inc/migrations.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $CONFIG = require __DIR__ . '/../config.php';
    $pdo = new PDO("mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4", $CONFIG['db_user'], $CONFIG['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    foreach (run_migrations($pdo) as $name) echo "applied $name\n";
    echo "migrations up to date\n";
}
