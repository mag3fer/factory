<?php
// Barcode sticker for one package, sized for the Xprinter XP-428B label roll (size from settings).
require __DIR__ . '/inc/bootstrap.php';
require_role();

$p = q("SELECT p.*, f.code fcode, f.name fname, f.spec, f.lot_no, f.grade, r.name rname, r.lot_no rlot, c.name cname
        FROM packages p JOIN items f ON f.id=p.finished_item_id
        LEFT JOIN items r ON r.id=p.raw_item_id LEFT JOIN items c ON c.id=p.cone_item_id
        WHERE p.id=?", [(int)($_GET['id'] ?? 0)])->fetch();
if (!$p) { http_response_code(404); exit('غير موجود'); }
$w = (int)setting('label_width_mm', '100');
$h = (int)setting('label_height_mm', '70');
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><title><?= e($p['serial']) ?></title>
<style>
@page { size: <?= $w ?>mm <?= $h ?>mm; margin: 0; }
html, body { margin: 0; padding: 0; }
body { width: <?= $w ?>mm; height: <?= $h ?>mm; font-family: Tahoma, Arial, sans-serif; color: #000; }
.lbl { box-sizing: border-box; width: 100%; height: 100%; padding: 2.5mm 3mm; display: flex; flex-direction: column; }
.top { display: flex; justify-content: space-between; align-items: center; border-bottom: .4mm solid #000; padding-bottom: 1mm; }
.co { font-size: 15pt; font-weight: 700; letter-spacing: .5pt; direction: ltr; }
.ph { font-size: 7.5pt; direction: ltr; text-align: left; }
.name { font-size: 12pt; font-weight: 700; margin: 1.2mm 0 .8mm; line-height: 1.15; }
table { width: 100%; border-collapse: collapse; font-size: 9pt; }
td { padding: .3mm 0; } td.k { color: #000; width: 22%; } td.v { font-weight: 700; direction: ltr; text-align: right; }
.net { font-size: 20pt; font-weight: 800; direction: ltr; }
.bc { margin-top: auto; text-align: center; }
.bc svg { width: 100%; height: 13mm; }
.bc .s { font-size: 9pt; letter-spacing: 1pt; direction: ltr; }
@media screen { body { margin: 10px auto; outline: 1px dashed #999; } }
</style></head>
<body><div class="lbl">
  <div class="top"><div class="co"><?= e(setting('company_name')) ?></div><div class="ph"><?= e(setting('company_phone1')) ?><br><?= e(setting('company_phone2')) ?></div></div>
  <div class="name"><?= e($p['fname']) ?></div>
  <table>
    <tr><td class="k">الكود</td><td class="v"><?= e($p['fcode']) ?></td><td class="k">الصافي</td><td class="v net" rowspan="2"><?= fmt_qty($p['net'], 2) ?> kg</td></tr>
    <tr><td class="k">اللوط</td><td class="v"><?= e($p['lot_no'] ?: $p['rlot']) ?></td><td class="k"></td></tr>
    <tr><td class="k">الكونات</td><td class="v"><?= (int)$p['cones_count'] ?></td><td class="k">القائم</td><td class="v"><?= fmt_qty($p['gross'], 2) ?> kg</td></tr>
    <tr><td class="k">التاريخ</td><td class="v"><?= e(date('d/m/Y H:i', strtotime($p['created_at']))) ?></td><td class="k">الدرجة</td><td class="v"><?= e($p['grade']) ?></td></tr>
  </table>
  <div class="bc"><svg id="bc"></svg><div class="s"><?= e($p['serial']) ?></div></div>
</div>
<script src="assets/JsBarcode.all.min.js"></script>
<script>JsBarcode('#bc', <?= json_encode($p['serial']) ?>, { format: 'CODE128', displayValue: false, margin: 0, height: 50, width: 2 });</script>
</body></html>
