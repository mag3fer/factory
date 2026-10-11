<?php
// CLI only: php install/install.php [admin_password]
// Creates the database, tables, seed data, the admin user and opening balances.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$CONFIG = require __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host={$CONFIG['db_host']};charset=utf8mb4", $CONFIG['db_user'], $CONFIG['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db = $CONFIG['db_name'];
$exists = $pdo->query("SHOW DATABASES LIKE " . $pdo->quote($db))->fetchColumn();
if ($exists) {
    fwrite(STDERR, "Database '$db' already exists — refusing to overwrite.\n");
    exit(1);
}
$pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$db`");

foreach (['schema.sql', 'seed.sql'] as $f) {
    $sql = file_get_contents(__DIR__ . "/$f");
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
        if (preg_match('/^(--[^\n]*\n?)*$/', $stmt)) continue;
        $pdo->exec($stmt);
    }
    echo "ran $f\n";
}

$adminPass = $argv[1] ?? bin2hex(random_bytes(5));
$pdo->prepare('INSERT INTO users (username, full_name, password_hash, role) VALUES (?,?,?,?)')
    ->execute(['admin', 'مدير النظام', password_hash($adminPass, PASSWORD_DEFAULT), 'admin']);
$adminId = (int)$pdo->lastInsertId();
file_put_contents(__DIR__ . '/ADMIN_PASSWORD.txt', "username: admin\npassword: $adminPass\n(change it from المستخدمين then delete this file)\n");

// Opening balances for packaging taken from Al-Mohtaseb inventory report on 2026-10-11
// (raw POY and finished yarn were zeroed there on 2026-10-04, so they start at 0).
$opening = [
    '1201004' => 188, '1201005' => 335, '1201006' => 7615, '1201007' => 4420,
    '1201008' => 8365, '1201009' => 1439, '1202001' => 141, '1202002' => 989,
];
$today = date('Y-m-d');
$pdo->prepare("INSERT INTO docs (type, number, doc_date, warehouse_id, notes, user_id) VALUES ('opening', 1, ?, 1, ?, ?)")
    ->execute([$today, 'أرصدة الكونات والكراتين منقولة من المحتسب', $adminId]);
$docId = (int)$pdo->lastInsertId();
$line = $pdo->prepare('INSERT INTO doc_lines (doc_id, item_id, qty, pieces) VALUES (?,?,?,?)');
$move = $pdo->prepare("INSERT INTO stock_moves (item_id, warehouse_id, qty, pieces, source, source_id, move_date) VALUES (?,1,?,?,'doc',?,?)");
$itemId = $pdo->prepare('SELECT id FROM items WHERE code=?');
foreach ($opening as $code => $qty) {
    $itemId->execute([$code]);
    $id = (int)$itemId->fetchColumn();
    $line->execute([$docId, $id, $qty, $qty]);
    $move->execute([$id, $qty, $qty, $docId, "$today 00:00:00"]);
}

echo "done. admin password written to install/ADMIN_PASSWORD.txt\n";
