<?php
// Loaded by every page: config, DB, session, helpers.
declare(strict_types=1);

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config.php — copy config.sample.php to config.php');
}
$CONFIG = require $configFile;
date_default_timezone_set($CONFIG['timezone'] ?? 'Africa/Cairo');

session_name('factory_sid');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $dsn = "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_qty($n, int $dec = 3): string
{
    return number_format((float)$n, $dec, '.', ',');
}

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q('SELECT skey, svalue FROM settings')->fetchAll() as $r) {
            $cache[$r['skey']] = (string)$r['svalue'];
        }
    }
    return $cache[$key] ?? $default;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), (string)$sent)) {
        http_response_code(400);
        exit('طلب غير صالح (CSRF) — أعد تحميل الصفحة');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

const ROLE_NAMES = ['admin' => 'مدير', 'store' => 'أمين مخزن', 'scale' => 'عامل ميزان'];

/** Require login and (optionally) one of the given roles. Admin passes every check. */
function require_role(string ...$roles): array
{
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    if ($roles && $u['role'] !== 'admin' && !in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('ليس لديك صلاحية لهذه الصفحة');
    }
    return $u;
}

function flash(?string $msg = null, string $type = 'success'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

const ITEM_TYPES = ['raw' => 'خام POY', 'finished' => 'خيط تام', 'cone' => 'كونات', 'pack' => 'كرتونة / شكارة', 'other' => 'أخرى'];
const UNITS = ['kg' => 'كيلو', 'pcs' => 'قطعة'];
const DOC_TYPES = ['receipt' => 'استلام / مشتريات', 'issue' => 'صرف للتشغيل', 'adjust' => 'تسوية', 'opening' => 'رصيد افتتاحي'];

/** Stock balance per item (qty, pieces). */
function item_balance(int $itemId): array
{
    $r = q('SELECT COALESCE(SUM(qty),0) qty, COALESCE(SUM(pieces),0) pieces FROM stock_moves WHERE item_id=?', [$itemId])->fetch();
    return ['qty' => (float)$r['qty'], 'pieces' => (int)$r['pieces']];
}

function items_by_type(string ...$types): array
{
    $in = implode(',', array_fill(0, count($types), '?'));
    return q("SELECT * FROM items WHERE active=1 AND type IN ($in) ORDER BY code", $types)->fetchAll();
}
