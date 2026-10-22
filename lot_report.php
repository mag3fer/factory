<?php
// Printable lot statement (كشف حركة لوط): one page for the owner showing a lot from start to finish —
// raw received / sold / issued to production, yarn produced / sold / left, every invoice with its customer and the production per day.
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
require_role('store');

$lotsList = q("SELECT i.lot_no, COALESCE(MAX(NULLIF(i.spec, '')), MAX(NULLIF(i.poy_spec, ''))) spec FROM items i
               JOIN stock_moves m ON m.item_id = i.id WHERE i.lot_no <> '' AND i.unit = 'kg' GROUP BY i.lot_no ORDER BY i.lot_no")->fetchAll();
$lot = trim((string)($_GET['lot'] ?? ''));
$logo = setting('logo_file');
$pageTitle = 'كشف حركة لوط';

if ($lot === '' || !in_array($lot, array_column($lotsList, 'lot_no'), true)) {
    require __DIR__ . '/inc/header.php'; ?>
    <div class="page-head"><h4><i class="bi bi-file-earmark-text"></i> كشف حركة لوط</h4></div>
    <form class="card p-3" method="get"><label class="form-label">اختار اللوط</label>
      <div class="d-flex gap-2"><select name="lot" class="form-select"><?php foreach ($lotsList as $l): ?><option value="<?= e($l['lot_no']) ?>"><?= e($l['lot_no'] . ($l['spec'] ? ' — ' . $l['spec'] : '')) ?></option><?php endforeach; ?></select>
      <button class="btn btn-primary">عرض</button></div></form>
    <?php require __DIR__ . '/inc/footer.php'; exit;
}

$items = q("SELECT id, code, name, type, unit, spec, poy_spec FROM items WHERE lot_no = ? AND unit = 'kg'
            AND EXISTS (SELECT 1 FROM stock_moves x WHERE x.item_id = items.id) ORDER BY FIELD(type,'raw','finished','other'), code", [$lot])->fetchAll();
$ids = array_map(fn($i) => (int)$i['id'], $items);
$in = implode(',', $ids ?: [0]);
$spec = '';
foreach ($items as $i) if ($i['spec']) { $spec = $i['spec']; break; }
if ($spec === '') foreach ($items as $i) if ($i['poy_spec']) { $spec = $i['poy_spec']; break; }

$tot = [];   // per item: in / sold / prod (raw used or yarn made) / other / bal
foreach (q("SELECT item_id, source, SUM(qty) q, SUM(qty > 0) pos FROM stock_moves WHERE item_id IN ($in) GROUP BY item_id, source, (qty > 0)")->fetchAll() as $r) {
    $t = &$tot[(int)$r['item_id']];
    $t = $t ?? ['in' => 0.0, 'sold' => 0.0, 'pkg_in' => 0.0, 'pkg_out' => 0.0, 'other' => 0.0];
    $v = (float)$r['q'];
    if ($r['source'] === 'sale') $t['sold'] += -$v;
    elseif ($r['source'] === 'package') { if ($v > 0) $t['pkg_in'] += $v; else $t['pkg_out'] += -$v; }
    elseif ($v > 0) $t['in'] += $v; else $t['other'] += -$v;
    unset($t);
}
$packs = q("SELECT p.finished_item_id id, SUM(pi.name IS NULL OR pi.name NOT LIKE '%شكار%') cartons, SUM(pi.name LIKE '%شكار%') sacks,
              SUM(p.status = 'in_stock' AND (pi.name IS NULL OR pi.name NOT LIKE '%شكار%')) c_left, SUM(p.status = 'in_stock' AND pi.name LIKE '%شكار%') s_left
            FROM packages p LEFT JOIN items pi ON pi.id = p.pack_item_id WHERE p.status <> 'void' AND p.finished_item_id IN ($in) GROUP BY p.finished_item_id")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

// ledger: documents + invoices (production is shown per day below)
$ledger = [];
foreach (q("SELECT m.move_date d, m.item_id, m.qty, d.type dt, d.number no, p.name party
            FROM stock_moves m JOIN docs d ON m.source = 'doc' AND d.id = m.source_id AND d.voided = 0 LEFT JOIN parties p ON p.id = d.party_id
            WHERE m.item_id IN ($in)")->fetchAll() as $r) {
    $ledger[] = ['d' => $r['d'], 'item' => (int)$r['item_id'], 'kind' => DOC_TYPES[$r['dt']] . ' رقم ' . $r['no'], 'who' => (string)$r['party'], 'qty' => (float)$r['qty'], 'link' => '', 'note' => '', 'recv' => $r['qty'] > 0];
}
foreach (q("SELECT m.move_date d, m.item_id, m.qty, s.id sid, s.number no, s.type st, c.name party,
              (SELECT SUM(l.packages_count) FROM sales_lines l WHERE l.invoice_id = s.id AND l.item_id = m.item_id) pc
            FROM stock_moves m JOIN sales_invoices s ON m.source = 'sale' AND s.id = m.source_id LEFT JOIN parties c ON c.id = s.customer_id
            WHERE m.item_id IN ($in) AND s.voided = 0")->fetchAll() as $r) {
    $ledger[] = ['d' => $r['d'], 'item' => (int)$r['item_id'], 'kind' => ($r['st'] === 'return' ? 'مرتجع بيع رقم ' : 'فاتورة بيع رقم ') . $r['no'], 'who' => (string)$r['party'], 'qty' => (float)$r['qty'],
        'link' => 'sale_view.php?id=' . (int)$r['sid'], 'note' => $r['pc'] ? (int)$r['pc'] . ' كرتونة/شكارة' : '', 'recv' => false];
}
usort($ledger, fn($a, $b) => strcmp($a['d'], $b['d']));
$byId = array_column($items, null, 'id');

$daily = q("SELECT DATE(p.created_at) d, p.finished_item_id item_id, SUM(pi.name IS NULL OR pi.name NOT LIKE '%شكار%') cartons, SUM(pi.name LIKE '%شكار%') sacks,
              SUM(p.cones_count) cones, SUM(p.net) net, SUM(p.is_mixed) mixed
            FROM packages p LEFT JOIN items pi ON pi.id = p.pack_item_id WHERE p.status <> 'void' AND p.finished_item_id IN ($in)
            GROUP BY DATE(p.created_at), p.finished_item_id ORDER BY d, p.finished_item_id")->fetchAll();

require __DIR__ . '/inc/header.php';
$dec = fn($v) => fmt_qty((float)$v, 3);
$avgPal = (float)setting('raw_container_kg', '25110') / max(1, (int)setting('raw_container_pallets', '44'));
$avg = fn(float $kg) => '<div class="small">≈ ' . number_format($kg / $avgPal, 1) . ' بالتة (بالمتوسط)</div>';
$pal = fn(float $kg, bool $rc) => ($i = pallet_text($kg, $rc)) ? '<div class="small">' . e($i) . '</div>' : '';
?>
<style>
.inv-sheet { position: relative; }
.sheet-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: .6rem; }
.sheet-head img { height: 78px; }
.sheet-co { text-align: right; line-height: 1.5; }
.sheet-co .co { font-size: 1.3rem; font-weight: 800; }
.sheet-title { text-align: center; margin: .2rem auto .8rem; }
.sheet-title span { display: inline-block; background: #d0d3d8; border: 1px solid #333; padding: .15rem 3rem; font-size: 1.5rem; font-weight: 800; }
.lot-box { display: flex; flex-wrap: wrap; gap: .5rem; margin: .5rem 0 .8rem; }
.lot-box div { border: 1px solid #333; padding: .3rem .8rem; min-width: 150px; text-align: center; }
.lot-box b { display: block; font-size: 1.1rem; }
h6.sec { background: #eef0f3; border: 1px solid #333; padding: .25rem .6rem; margin: .9rem 0 .3rem; font-weight: 800; }
table.inv { width: 100%; border-collapse: collapse; }
table.inv th { background: #d0d3d8; border: 1px solid #333; padding: .3rem .5rem; text-align: center; }
table.inv td { border: 1px solid #333; padding: .25rem .5rem; text-align: center; }
table.inv td.name { text-align: right; }
.sign { display: flex; justify-content: space-between; margin-top: 2.2rem; }
.sign div { width: 30%; text-align: center; border-top: 1px solid #333; padding-top: .3rem; }
@page { size: A4 landscape; margin: 0; }
/* compact layout used while printing (class added in beforeprint so the fit-to-page measurement matches the printed layout) */
.inv-sheet.printing { width: 297mm; padding: 7mm 9mm !important; font-size: 10pt; }
.printing .sheet-head img { height: 52px; }
.printing .sheet-co .co { font-size: 1.1rem; }
.printing .sheet-title { margin: 0 auto .4rem; } .printing .sheet-title span { font-size: 1.15rem; padding: .05rem 2rem; }
.printing .lot-box { flex-wrap: nowrap; gap: .3rem; margin: .25rem 0 .4rem; } .printing .lot-box div { min-width: 0; flex: 1; padding: .15rem .4rem; font-size: 9pt; } .printing .lot-box b { font-size: 10.5pt; }
.printing h6.sec { margin: .45rem 0 .2rem; padding: .1rem .5rem; font-size: 10pt; }
.printing table.inv th, .printing table.inv td { padding: .1rem .35rem; }
.printing .sign { margin-top: 1rem; }
@media print {
  body { margin: 0; color: #000 !important; }
  .card.inv-sheet { box-shadow: none; }
  .inv-sheet, .inv-sheet * { color: #000 !important; opacity: 1 !important; }
  .inv-sheet { font-weight: 600; } .inv-sheet b, .inv-sheet th, .inv-sheet .co { font-weight: 800; }
  .sheet-title span, table.inv th, h6.sec { background: #d0d3d8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.inv thead { display: table-header-group; } table.inv tr { page-break-inside: avoid; }
  a { text-decoration: none; }
}
</style>
<div class="page-head no-print"><h4><i class="bi bi-file-earmark-text"></i> كشف حركة لوط</h4>
  <form method="get" class="d-flex gap-2"><select name="lot" class="form-select"><?php foreach ($lotsList as $l): ?><option value="<?= e($l['lot_no']) ?>"<?= $l['lot_no'] === $lot ? ' selected' : '' ?>><?= e($l['lot_no'] . ($l['spec'] ? ' — ' . $l['spec'] : '')) ?></option><?php endforeach; ?></select>
  <button class="btn btn-primary">عرض</button><button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i> طباعة</button></form></div>
<div class="card p-4 inv-sheet">
  <div class="sheet-head">
    <div class="sheet-co"><div class="co"><?= e(setting('company_name')) ?></div><div><?= e(setting('company_address')) ?></div>
      <div class="num"><?= e(setting('company_phone1')) ?></div></div>
    <div><?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><img src="assets/brand.svg" alt=""><?php endif; ?></div>
  </div>
  <div class="sheet-title"><span>كشف حركة لوط</span></div>
  <div class="mb-2"><span>اللوط :</span> <b class="num"><?= e($lot) ?></b> &nbsp;&nbsp; <span>النمرة :</span> <b class="num"><?= e($spec) ?></b> &nbsp;&nbsp; <span>تاريخ الطباعة :</span> <b class="num"><?= date('d/m/Y') ?></b></div>

<?php foreach ($items as $it): if ($it['type'] !== 'raw') continue; $t = $tot[$it['id']]; $left = $t['in'] - $t['sold'] - $t['pkg_out'] - $t['other']; ?>
  <h6 class="sec">الخام — <?= e($it['name']) ?> (<?= e($it['code']) ?>)</h6>
  <div class="lot-box">
    <div>المستلم<b class="num"><?= $dec($t['in']) ?></b><?= $pal($t['in'], true) ?></div>
    <div>اتباع خام<b class="num"><?= $dec($t['sold']) ?></b><?= $pal($t['sold'], true) ?></div>
    <div>اتصرف للإنتاج<b class="num"><?= $dec($t['pkg_out']) ?></b><?= $avg($t['pkg_out']) ?></div>
    <div>الباقي حسب النظام<b class="num"><?= $dec($left) ?></b><?= $avg($left) ?></div>
    <div>الباقي الفعلي (جرد)<b>&nbsp;</b><span>…………………</span></div>
    <div>الهالك<b>&nbsp;</b><span>…………………</span></div>
  </div>
<?php endforeach; ?>

<?php $fin = array_filter($items, fn($i) => $i['type'] === 'finished'); if ($fin): ?>
  <h6 class="sec">الخيط التام المنتج من اللوط</h6>
  <table class="inv"><thead><tr><th>النمرة</th><th>الصنف</th><th>اتنتج (كجم)</th><th>كراتين</th><th>شكاير</th><th>اتباع (كجم)</th><th>الباقي (كجم)</th><th>الباقي كراتين</th><th>الباقي شكاير</th></tr></thead>
  <?php $s = ['p' => 0, 's' => 0, 'b' => 0]; foreach ($fin as $it): $t = $tot[$it['id']]; $pk = $packs[$it['id']] ?? ['cartons' => 0, 'sacks' => 0, 'c_left' => 0, 's_left' => 0];
      $s['p'] += $t['pkg_in']; $s['s'] += $t['sold']; $s['b'] += $t['pkg_in'] + $t['in'] - $t['sold'] - $t['other']; ?>
    <tr><td class="num"><?= e($it['code']) ?></td><td class="name"><?= e($it['name']) ?></td><td class="num"><?= $dec($t['pkg_in']) ?></td><td><?= (int)$pk['cartons'] ?></td><td><?= (int)$pk['sacks'] ?></td>
      <td class="num"><?= $dec($t['sold']) ?></td><td class="num fw-bold"><?= $dec($t['pkg_in'] + $t['in'] - $t['sold'] - $t['other']) ?></td><td><?= (int)$pk['c_left'] ?></td><td><?= (int)$pk['s_left'] ?></td></tr>
  <?php endforeach; ?>
    <tr><td colspan="2" class="name fw-bold">الإجمالي</td><td class="num fw-bold"><?= $dec($s['p']) ?></td><td></td><td></td><td class="num fw-bold"><?= $dec($s['s']) ?></td><td class="num fw-bold"><?= $dec($s['b']) ?></td><td></td><td></td></tr>
  </table>
<?php endif; ?>

  <h6 class="sec">الاستلام والمبيعات (بالتاريخ)</h6>
  <table class="inv"><thead><tr><th>التاريخ</th><th>البيان</th><th>العميل / المورد</th><th>الصنف</th><th>داخل (كجم)</th><th>خارج (كجم)</th><th>تفصيل</th></tr></thead>
  <?php foreach ($ledger as $r): $it = $byId[$r['item']] ?? null; $note = ($it && $it['type'] === 'raw') ? pallet_text($r['qty'], true) : $r['note']; ?>
    <tr><td class="num"><?= e(substr($r['d'], 0, 10)) ?></td><td class="name"><?= $r['link'] ? '<a href="' . e($r['link']) . '">' . e($r['kind']) . '</a>' : e($r['kind']) ?></td><td class="name"><?= e($r['who']) ?></td>
      <td class="name"><?= e($it['code'] ?? '') ?> <?= ($it && $it['type'] === 'raw') ? '(خام)' : '' ?></td>
      <td class="num"><?= $r['qty'] > 0 ? $dec($r['qty']) : '' ?></td><td class="num"><?= $r['qty'] < 0 ? $dec(-$r['qty']) : '' ?></td><td class="name small"><?= e($note) ?></td></tr>
  <?php endforeach; if (!$ledger): ?><tr><td colspan="7">مفيش حركة</td></tr><?php endif; ?>
  </table>

<?php if ($daily): ?>
  <h6 class="sec">الإنتاج يوم بيوم</h6>
  <table class="inv"><thead><tr><th>التاريخ</th><th>النمرة</th><th>كراتين</th><th>شكاير</th><th>كونات</th><th>صافي (كجم)</th></tr></thead>
  <?php $dt = ['c' => 0, 's' => 0, 'k' => 0, 'n' => 0.0]; foreach ($daily as $r): $dt['c'] += $r['cartons']; $dt['s'] += $r['sacks']; $dt['k'] += $r['cones']; $dt['n'] += $r['net']; ?>
    <tr><td class="num"><?= e($r['d']) ?></td><td class="num"><?= e($byId[(int)$r['item_id']]['code'] ?? '') ?></td><td><?= (int)$r['cartons'] ?></td><td><?= (int)$r['sacks'] ?></td><td><?= (int)$r['cones'] ?></td><td class="num"><?= $dec($r['net']) ?></td></tr>
  <?php endforeach; ?>
    <tr><td colspan="2" class="fw-bold">الإجمالي</td><td class="fw-bold"><?= $dt['c'] ?></td><td class="fw-bold"><?= $dt['s'] ?></td><td class="fw-bold"><?= $dt['k'] ?></td><td class="num fw-bold"><?= $dec($dt['n']) ?></td></tr>
  </table>
<?php endif; ?>
  <div class="small mt-2">البالتة العادية <?= fmt_qty((float)setting('raw_pallet_kg', '558'), 0) ?> كجم (36 كونة) والكبيرة <?= fmt_qty((float)setting('raw_pallet_big_kg', '651'), 0) ?> كجم (42 كونة)، والحاوية <?= (int)setting('raw_container_pallets', '44') ?> بالتة. أرقام البالتات تقديرية لما الكمية مش بالتات كاملة.</div>
  <div class="sign"><div>أمين المخزن</div><div>المحاسب</div><div>المدير</div></div>
</div>
<script>
// keep the statement on a single landscape A4 sheet: shrink it to fit before printing
(function () {
  const sheet = document.querySelector('.inv-sheet');
  const PAGE_H = 210 / 25.4 * 96 - 8;   // A4 landscape height in px, minus a little margin
  window.addEventListener('beforeprint', () => {
    sheet.classList.add('printing');
    sheet.style.zoom = 1;
    const h = sheet.scrollHeight;
    if (h > PAGE_H) sheet.style.zoom = (PAGE_H / h).toFixed(3);
  });
  window.addEventListener('afterprint', () => { sheet.style.zoom = ''; sheet.classList.remove('printing'); });
})();
</script>
<?php require __DIR__ . '/inc/footer.php';
