<?php
// CLI only: php install/migrate.php — applies install/migrations/*.sql once each, in name order.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function run_migrations(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(__DIR__ . '/migrations/*.sql');
    sort($files);
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $done, true)) continue;
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', file_get_contents($file)))) as $stmt) {
            if (preg_match('/^(--[^\n]*\n?)*$/', $stmt)) continue;
            $pdo->exec($stmt);
        }
        $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
        echo "applied $name\n";
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $CONFIG = require __DIR__ . '/../config.php';
    $pdo = new PDO("mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4", $CONFIG['db_user'], $CONFIG['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    run_migrations($pdo);
    echo "migrations up to date\n";
}
