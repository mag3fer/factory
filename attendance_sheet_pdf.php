<?php
// PDF of the printable attendance sheet (same filters as attendance_sheet.php).
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/hr.php';
require_once __DIR__ . '/inc/pdf.php';
require_role('admin');

ob_start();
require __DIR__ . '/attendance_sheet.php';
$html = ob_get_clean();
try { $pdf = html_to_pdf($html); }
catch (RuntimeException $ex) { http_response_code(500); exit(e($ex->getMessage())); }
send_pdf($pdf, 'كشف حضور ' . $from . ' إلى ' . $to);
