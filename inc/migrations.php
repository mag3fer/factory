<?php
// Applies install/migrations/*.sql once each, in name order. Used by install/migrate.php (CLI)
// and by the admin page نسخة احتياطية وتصفير (hosts without a command line).
declare(strict_types=1);

/** Migration files not applied yet. */
function pending_migrations(PDO $pdo): array
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(__DIR__ . '/../install/migrations/*.sql') ?: [];
    sort($files);
    return array_values(array_filter($files, fn($f) => !in_array(basename($f), $done, true)));
}

/** Runs the pending migrations; returns the names applied. */
function run_migrations(PDO $pdo): array
{
    $applied = [];
    foreach (pending_migrations($pdo) as $file) {
        $name = basename($file);
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', file_get_contents($file)))) as $stmt) {
            if (preg_match('/^(--[^\n]*\n?)*$/', $stmt)) continue;
            $pdo->exec($stmt);
        }
        $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
        $applied[] = $name;
    }
    return $applied;
}
