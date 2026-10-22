<?php
// PDF of the warehouse inventory (same filters as stock.php): ?type=&from=&to=&show=&flat=
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pdf.php';
require_role('store');

unset($_GET['csv']);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require __DIR__ . '/stock.php';
$html = ob_get_clean();
try { $pdf = html_to_pdf($html); }
catch (RuntimeException $ex) { http_response_code(500); exit(e($ex->getMessage())); }
send_pdf($pdf, $fileName);
