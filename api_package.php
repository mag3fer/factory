<?php
// JSON endpoint for the scale station: save a weighed package, mark printed, void.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/stock.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$u = current_user();
if (!$u) out(['ok' => false, 'error' => 'انتهت الجلسة — سجّل دخول تاني'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);
csrf_check();

$action = $_POST['action'] ?? '';
try {
    switch ($action) {
        case 'save':
            if (!in_array($u['role'], ['admin', 'scale'], true)) out(['ok' => false, 'error' => 'غير مسموح'], 403);
            $r = save_package($_POST, $u['id']);
            out(['ok' => true] + $r);
        case 'printed':
            q('UPDATE packages SET printed_count = printed_count + 1 WHERE id=?', [(int)$_POST['id']]);
            out(['ok' => true]);
        case 'void':
            if (!in_array($u['role'], ['admin', 'store'], true)) out(['ok' => false, 'error' => 'الإلغاء للمدير أو أمين المخزن'], 403);
            void_package((int)$_POST['id'], trim($_POST['reason'] ?? ''));
            out(['ok' => true]);
        default:
            out(['ok' => false, 'error' => 'unknown action'], 400);
    }
} catch (InvalidArgumentException | RuntimeException $ex) {
    out(['ok' => false, 'error' => $ex->getMessage()], 422);
}
