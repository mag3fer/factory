<?php
// JSON endpoint for the sales invoice screen: scan lookup, last price, save.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$u = current_user();
if (!$u) out(['ok' => false, 'error' => 'انتهت الجلسة — سجّل دخول تاني'], 401);
if (!in_array($u['role'], ['admin', 'store'], true)) out(['ok' => false, 'error' => 'غير مسموح'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST only'], 405);
csrf_check();

$type = ($_POST['type'] ?? '') === 'return' ? 'return' : 'sale';
$customerId = (int)($_POST['customer_id'] ?? 0);
try {
    switch ($_POST['action'] ?? '') {
        case 'lookup':
            $p = lookup_package((string)($_POST['serial'] ?? ''), $type, $customerId);
            out(['ok' => true, 'package' => [
                'id' => (int)$p['id'], 'serial' => $p['serial'], 'item_id' => (int)$p['finished_item_id'], 'code' => $p['code'], 'name' => $p['name'],
                'net' => (float)$p['net'], 'cones' => (int)$p['cones_count'], 'sold_price' => isset($p['sold_price']) ? (float)$p['sold_price'] : null,
            ]]);
        case 'balance':
            out(['ok' => true, 'balance' => party_balance($customerId)]);
        case 'last_price':
            // last price this customer paid for the item (falls back to the last price for anyone)
            $item = (int)($_POST['item_id'] ?? 0);
            $sql = "SELECT l.price FROM sales_lines l JOIN sales_invoices s ON s.id=l.invoice_id WHERE l.item_id=? AND s.type='sale' AND s.voided=0";
            $price = q("$sql AND s.customer_id=? ORDER BY s.inv_date DESC, s.id DESC LIMIT 1", [$item, $customerId])->fetchColumn();
            if ($price === false) $price = q("$sql ORDER BY s.inv_date DESC, s.id DESC LIMIT 1", [$item])->fetchColumn();
            out(['ok' => true, 'price' => $price === false ? null : (float)$price]);
        case 'save':
            $data = json_decode((string)($_POST['data'] ?? ''), true);
            if (!is_array($data)) out(['ok' => false, 'error' => 'بيانات غير صحيحة'], 422);
            $id = save_sale([
                'type' => $type, 'inv_date' => $data['inv_date'] ?? '', 'customer_id' => $customerId, 'notes' => $data['notes'] ?? '',
                'discount' => $data['discount'] ?? 0, 'paid' => $data['paid'] ?? 0, 'pay_method' => $data['pay_method'] ?? 'cash',
            ], (array)($data['packages'] ?? []), (array)($data['prices'] ?? []), (array)($data['manual'] ?? []), $u['id']);
            out(['ok' => true, 'id' => $id]);
        default:
            out(['ok' => false, 'error' => 'unknown action'], 400);
    }
} catch (InvalidArgumentException | RuntimeException $ex) {
    out(['ok' => false, 'error' => $ex->getMessage()], 422);
}
