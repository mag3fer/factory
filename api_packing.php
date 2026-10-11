<?php
// Edit packing types from the scale station. Any logged-in user may call it, but every save
// must carry an active admin's password (checked here), with a lockout after repeated failures.
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!current_user()) out(['ok' => false, 'error' => 'انتهت الجلسة — سجّل دخول تاني'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);
csrf_check();

$lock = $_SESSION['admin_pw_lock'] ?? ['fails' => 0, 'until' => 0];
if ($lock['until'] > time()) {
    out(['ok' => false, 'error' => 'محاولات غلط كتير — استنى ' . ceil(($lock['until'] - time()) / 60) . ' دقيقة'], 429);
}

$pass = (string)($_POST['admin_password'] ?? '');
$approver = null;
foreach (q("SELECT id, full_name, password_hash FROM users WHERE role='admin' AND active=1")->fetchAll() as $a) {
    if ($pass !== '' && password_verify($pass, $a['password_hash'])) { $approver = $a; break; }
}
if (!$approver) {
    $lock['fails']++;
    if ($lock['fails'] >= 5) $lock = ['fails' => 0, 'until' => time() + 600];
    $_SESSION['admin_pw_lock'] = $lock;
    out(['ok' => false, 'error' => 'باسورد المدير غلط'], 403);
}
$_SESSION['admin_pw_lock'] = ['fails' => 0, 'until' => 0];

$rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
if (!is_array($rows) || !$rows) out(['ok' => false, 'error' => 'مفيش بيانات'], 422);
$packIds = array_map('intval', q("SELECT id FROM items WHERE type='pack'")->fetchAll(PDO::FETCH_COLUMN));

$clean = [];
foreach ($rows as $r) {
    $name = trim((string)($r['name'] ?? ''));
    $w = [];
    foreach (['cone_weight', 'pack_weight', 'bag_weight'] as $k) {
        if (!is_numeric($r[$k] ?? null)) out(['ok' => false, 'error' => "وزن غير صحيح في: $name"], 422);
        $w[$k] = round((float)$r[$k], 3);
        if ($w[$k] < 0 || $w[$k] > 50) out(['ok' => false, 'error' => "وزن غير منطقي في: $name"], 422);
    }
    $id = (int)($r['id'] ?? 0);
    if ($name === '') {
        if ($id === 0) continue; // empty new row
        out(['ok' => false, 'error' => 'الاسم مطلوب'], 422);
    }
    $pack = (int)($r['pack_item_id'] ?? 0);
    $clean[] = ['id' => $id, 'name' => $name, 'pack' => in_array($pack, $packIds, true) ? $pack : null, 'active' => empty($r['active']) ? 0 : 1] + $w;
}

$pdo = db();
$pdo->beginTransaction();
try {
    foreach ($clean as $c) {
        $vals = [$c['name'], $c['pack'], $c['cone_weight'], $c['pack_weight'], $c['bag_weight'], $c['active']];
        if ($c['id']) q('UPDATE packing_types SET name=?, pack_item_id=?, cone_weight=?, pack_weight=?, bag_weight=?, active=? WHERE id=?', [...$vals, $c['id']]);
        else q('INSERT INTO packing_types (name, pack_item_id, cone_weight, pack_weight, bag_weight, active) VALUES (?,?,?,?,?,?)', $vals);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    out(['ok' => false, 'error' => 'خطأ في الحفظ'], 500);
}
$types = q('SELECT id, name, pack_item_id, cone_weight, pack_weight, bag_weight, active FROM packing_types ORDER BY id')->fetchAll();
out(['ok' => true, 'types' => $types, 'approved_by' => $approver['full_name']]);
