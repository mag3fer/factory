<?php
// PDF of a sales document (invoice / delivery note): renders sale_view.php with the chosen print template and prints it to PDF,
// so the file looks exactly like the printed sheet. ?id=..&tpl=..&cartons=1&inline=1
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sales.php';
require_once __DIR__ . '/inc/pdf.php';
$u = require_role('store');

$id = (int)($_GET['id'] ?? 0);
$inv = q('SELECT s.number, s.kind, s.type, p.name customer FROM sales_invoices s JOIN parties p ON p.id=s.customer_id WHERE s.id=?', [$id])->fetch();
if (!$inv) { http_response_code(404); exit('غير موجود'); }

unset($_GET['print'], $_GET['new']);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require __DIR__ . '/sale_view.php';
$html = ob_get_clean();
if (!empty($_GET['cartons'])) {
    $html = preg_replace('/<body([^>]*)>/i', '<body$1 class="with-cartons">', $html, 1);
    $html = str_replace('id="cartonsBox"', 'id="cartonsBox" open', $html);
}
try { $pdf = html_to_pdf($html); }
catch (RuntimeException $ex) { http_response_code(500); exit(e($ex->getMessage())); }
send_pdf($pdf, sale_title($inv) . ' رقم ' . $inv['number'] . ' - ' . $inv['customer'], !empty($_GET['inline']));
