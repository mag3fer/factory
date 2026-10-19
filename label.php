<?php
// Barcode sticker for one package — same layout as the old TEXTILE LAND label.
// Xprinter XP-428B stock is 2.99" x 5.12" (76 x 130 mm portrait); size comes from settings.
require __DIR__ . '/inc/bootstrap.php';
require_role();

$p = q("SELECT p.*, f.code fcode, f.name fname, f.lot_no, f.grade, r.lot_no rlot, c.color cone_color, c.name cname, pl.serial pallet_serial, pl.capacity pallet_cap
        FROM packages p JOIN items f ON f.id=p.finished_item_id
        LEFT JOIN items r ON r.id=p.raw_item_id LEFT JOIN items c ON c.id=p.cone_item_id LEFT JOIN pallets pl ON pl.id=p.pallet_id
        WHERE p.id=?", [(int)($_GET['id'] ?? 0)])->fetch();
if (!$p) { http_response_code(404); exit('غير موجود'); }
$w = (int)setting('label_width_mm', '76');
$h = (int)setting('label_height_mm', '130');
$logo = setting('logo_label') ?: setting('logo_file');
$lot = $p['lot_no'] ?: $p['rlot'];
// The lot is printed in its own row, so drop it from the displayed item name when the name ends with it.
$itemName = $lot ? trim(preg_replace('/\s*' . preg_quote($lot, '/') . '\s*$/u', '', $p['fname'])) : $p['fname'];
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><title><?= e($p['serial']) ?></title>
<style>
@page { size: <?= $w ?>mm <?= $h ?>mm; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { width: <?= $w ?>mm; height: <?= $h ?>mm; font-family: Arial, Tahoma, sans-serif; color: #000; overflow: hidden; }
.lbl { width: 100%; height: 100%; padding: 2.5mm 12mm 3mm 4mm; display: flex; flex-direction: column; align-items: center; }
.logo { height: 40mm; max-width: 92mm; object-fit: contain; margin: 0 0 1mm; image-rendering: pixelated; }
.co { font-size: 21pt; font-weight: 800; letter-spacing: .3pt; direction: ltr; margin: 1mm 0 1.5mm; }
table.t { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
table.t td { border: .35mm solid #000; padding: .7mm 1.5mm; text-align: center; vertical-align: middle; }
td.en { direction: ltr; width: 26%; font-size: 8.5pt; white-space: nowrap; }
td.ar { width: 24%; font-weight: 700; font-size: 8.5pt; white-space: nowrap; }
td.v { font-weight: 700; font-size: 12pt; }
td.v.ltr { direction: ltr; }
tr.net td.v { font-size: 16pt; }
.info { width: 100%; font-size: 9pt; margin-top: 1.2mm; line-height: 1.25; }
.info .row { display: flex; justify-content: space-between; direction: ltr; }
.info .row b { font-weight: 700; white-space: nowrap; padding-right: 2mm; }
.addr { direction: rtl; text-align: right; }
.made { font-size: 9.5pt; font-weight: 700; margin-top: .5mm; }
.pal { width: 100%; margin-top: 1.2mm; border: .35mm solid #000; padding: .5mm 2mm; display: flex; justify-content: space-between; align-items: center; font-weight: 800; direction: ltr; }
.pal .pn { font-size: 11pt; letter-spacing: .5pt; } .pal .ps { font-size: 15pt; }
.bc { margin-top: auto; width: 100%; text-align: center; }
.bc svg { width: 92%; height: 13mm; }
.bc .s { display: flex; justify-content: space-between; font-size: 8pt; padding: 0 2mm; }
.bc .s .n { direction: ltr; font-size: 9.5pt; letter-spacing: .8pt; }
@media screen { body { margin: 10px auto; outline: 1px dashed #999; } }
</style></head>
<body><div class="lbl">
  <?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?><img class="logo" src="<?= e($logo) ?>?v=<?= filemtime(__DIR__ . '/' . $logo) ?>" alt="">
  <?php else: ?><div class="co"><?= e(setting('company_name', 'TEXTILE LAND')) ?></div><?php endif; ?>
  <table class="t">
    <tr><td class="ar">النمرة</td><td class="v"><?= e($itemName) ?></td><td class="en">Items</td></tr>
    <tr><td class="ar">اللوط</td><td class="v ltr"><?= e($lot) ?></td><td class="en">Lot</td></tr>
    <tr><td class="ar">اللون</td><td class="v"><?= e($p['cone_color'] ?: $p['cname']) ?></td><td class="en">Color</td></tr>
    <tr><td class="ar">عدد الكونات</td><td class="v ltr"><?= (int)$p['cones_count'] ?></td><td class="en">Cones NO.</td></tr>
    <tr><td class="ar">الوزن القائم</td><td class="v ltr"><?= fmt_qty($p['gross'], 2) ?></td><td class="en">Gross weight</td></tr>
    <tr class="net"><td class="ar">الوزن الصافي</td><td class="v ltr"><?= fmt_qty($p['net'], 3) ?> kg</td><td class="en">Net weight</td></tr>
  </table>
  <div class="info">
    <?php if (setting('company_phone1')): ?><div class="row"><b>Mobile 1 :</b><span><?= e(setting('company_phone1')) ?></span></div><?php endif; ?>
    <?php if (setting('company_phone2')): ?><div class="row"><b>Mobile 2 :</b><span><?= e(setting('company_phone2')) ?></span></div><?php endif; ?>
    <?php if (setting('company_address')): ?><div class="row"><b>Address :</b><span class="addr"><?= e(setting('company_address')) ?></span></div><?php endif; ?>
  </div>
  <div class="made">Made in Egypt - صنع في مصر</div>
  <?php if ($p['pallet_serial']): ?><div class="pal"><span class="pn">PALLET <?= e($p['pallet_serial']) ?></span><span class="ps"><?= (int)$p['pallet_seq'] ?> / <?= (int)$p['pallet_cap'] ?></span></div><?php endif; ?>
  <div class="bc"><svg id="bc"></svg>
    <div class="s"><span><?= e(date('d/m/Y H:i', strtotime($p['created_at']))) ?></span><span class="n"><?= e($p['serial']) ?></span><span><?= e($p['fcode']) ?></span></div>
  </div>
</div>
<script src="assets/JsBarcode.all.min.js"></script>
<script>JsBarcode('#bc', <?= json_encode($p['serial']) ?>, { format: 'CODE128', displayValue: false, margin: 0, height: 45, width: 2 });</script>
</body></html>
