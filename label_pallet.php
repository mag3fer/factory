<?php
// Sticker for a whole pallet (printed when its last carton is saved). Same stock size as the carton label.
require __DIR__ . '/inc/bootstrap.php';
require_role();

$pl = q('SELECT pl.*, f.code fcode, f.name fname, f.lot_no FROM pallets pl JOIN items f ON f.id=pl.finished_item_id WHERE pl.id=?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$pl) { http_response_code(404); exit('غير موجود'); }
$t = q("SELECT COUNT(*) n, COALESCE(SUM(cones_count),0) cones, COALESCE(SUM(gross),0) gross, COALESCE(SUM(net),0) net, MIN(created_at) first_at, MAX(created_at) last_at
        FROM packages WHERE pallet_id=? AND status<>'void'", [$pl['id']])->fetch();
$w = (int)setting('label_width_mm', '76');
$h = (int)setting('label_height_mm', '130');
$logo = setting('logo_label') ?: setting('logo_file');
$lot = $pl['lot_no'];
$itemName = $lot ? trim(preg_replace('/\s*' . preg_quote($lot, '/') . '\s*$/u', '', $pl['fname'])) : $pl['fname'];
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><title><?= e($pl['serial']) ?></title>
<style>
@page { size: <?= $w ?>mm <?= $h ?>mm; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { width: <?= $w ?>mm; height: <?= $h ?>mm; font-family: Arial, Tahoma, sans-serif; color: #000; overflow: hidden; }
.lbl { width: 100%; height: 100%; padding: 2mm 3.5mm; display: flex; flex-direction: column; align-items: center; }
.logo { height: 26mm; max-width: 70mm; object-fit: contain; image-rendering: pixelated; }
.co { font-size: 21pt; font-weight: 800; direction: ltr; margin: 1mm 0; }
.title { width: 100%; text-align: center; border: .5mm solid #000; margin: 2mm 0 1.5mm; padding: 1mm; font-size: 12pt; font-weight: 800; letter-spacing: 1pt; direction: ltr; }
.pno { font-size: 30pt; font-weight: 900; direction: ltr; letter-spacing: 1pt; margin: 1mm 0 2mm; }
table.t { width: 100%; border-collapse: collapse; font-size: 9pt; }
table.t td { border: .35mm solid #000; padding: 1mm 1.2mm; text-align: center; vertical-align: middle; }
td.en { direction: ltr; width: 26%; font-size: 8pt; white-space: nowrap; }
td.ar { width: 24%; font-weight: 700; font-size: 8pt; white-space: nowrap; }
td.v { font-weight: 700; font-size: 12pt; direction: ltr; }
tr.net td.v { font-size: 16pt; }
.bc { margin-top: auto; width: 100%; text-align: center; }
.bc svg { width: 92%; height: 14mm; }
.bc .s { display: flex; justify-content: space-between; font-size: 7.5pt; padding: 0 2mm; direction: ltr; }
@media screen { body { margin: 10px auto; outline: 1px dashed #999; } }
</style></head>
<body><div class="lbl">
  <?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?><img class="logo" src="<?= e($logo) ?>?v=<?= filemtime(__DIR__ . '/' . $logo) ?>" alt="">
  <?php else: ?><div class="co"><?= e(setting('company_name', 'TEXTILE LAND')) ?></div><?php endif; ?>
  <div class="title">PALLET — بالتة</div>
  <div class="pno"><?= e($pl['serial']) ?></div>
  <table class="t">
    <tr><td class="ar">النمرة</td><td class="v" style="direction:rtl"><?= e($itemName) ?></td><td class="en">Items</td></tr>
    <?php if ($lot): ?><tr><td class="ar">اللوط</td><td class="v"><?= e($lot) ?></td><td class="en">Lot</td></tr><?php endif; ?>
    <tr><td class="ar">عدد الكراتين</td><td class="v"><?= (int)$t['n'] ?> / <?= (int)$pl['capacity'] ?></td><td class="en">Cartons</td></tr>
    <tr><td class="ar">عدد الكونات</td><td class="v"><?= (int)$t['cones'] ?></td><td class="en">Cones NO.</td></tr>
    <tr><td class="ar">الوزن القائم</td><td class="v"><?= fmt_qty($t['gross'], 2) ?></td><td class="en">Gross weight</td></tr>
    <tr class="net"><td class="ar">الوزن الصافي</td><td class="v"><?= fmt_qty($t['net'], 2) ?> kg</td><td class="en">Net weight</td></tr>
  </table>
  <div class="bc"><svg id="bc"></svg>
    <div class="s"><span><?= e(date('d/m/Y H:i', strtotime($t['last_at'] ?: $pl['created_at']))) ?></span><span><?= e($pl['fcode']) ?></span></div>
  </div>
</div>
<script src="assets/JsBarcode.all.min.js"></script>
<script>JsBarcode('#bc', <?= json_encode($pl['serial']) ?>, { format: 'CODE128', displayValue: false, margin: 0, height: 50, width: 3 });</script>
</body></html>
