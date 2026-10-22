<?php
// Warehouse inventory (جرد المخزن), modelled on the Mohtaseb "جرد المواد" report: period in/out, period balance, total balance,
// filters by type / date range / balance sign, quick summary (finished kg, raw kg, cones, cartons, sacks), print, PDF (stock_pdf.php) and Excel (csv).
require_once __DIR__ . '/inc/bootstrap.php';
require_role('store');

$today = date('Y-m-d');
$validDate = fn(string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
$from = $validDate('from', date('Y-01-01'));
$to = $validDate('to', $today);
if ($from > $to) [$from, $to] = [$to, $from];
$type = isset(ITEM_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$show = in_array($_GET['show'] ?? '', ['all', 'pos', 'neg', 'zero'], true) ? $_GET['show'] : 'pos';   // default: only items that have stock
$grouped = !isset($_GET['flat']);
// finished yarn only: كراتين / شكاير and نظامي / متعدد (these filters work from the cartons themselves, not from the item balance)
$pk = in_array($_GET['pk'] ?? '', ['carton', 'sack'], true) ? $_GET['pk'] : '';
$md = in_array($_GET['md'] ?? '', ['std', 'mixed'], true) ? $_GET['md'] : '';
$pkFilter = $pk !== '' || $md !== '';
$lot = trim((string)($_GET['lot'] ?? ''));       // one lot number
$search = trim((string)($_GET['s'] ?? ''));     // count code / name
$lots = q("SELECT i.lot_no, COUNT(DISTINCT i.id) n, COALESCE(MAX(NULLIF(i.spec, '')), MAX(NULLIF(i.poy_spec, ''))) spec,
                   COALESCE(SUM(m.qty), 0) bal, COALESCE(-SUM(CASE WHEN m.source = 'sale' THEN m.qty END), 0) sold
            FROM items i JOIN stock_moves m ON m.item_id = i.id WHERE i.lot_no <> '' AND i.unit = 'kg' GROUP BY i.lot_no ORDER BY i.lot_no")->fetchAll();   // only lots that moved; bal = kg left in the whole lot
if ($pkFilter) $type = 'finished';
const PK_LABELS = ['carton' => 'كراتين بس', 'sack' => 'شكاير بس'];
const MD_LABELS = ['std' => 'نظامي بس', 'mixed' => 'متعدد بس'];
const SHOW_LABELS = ['all' => 'كل المواد', 'pos' => 'المواد الموجبة (فيها رصيد)', 'neg' => 'المواد السالبة', 'zero' => 'المواد الصفرية'];

$sql = "SELECT i.id, i.code, i.name, i.type, i.unit, i.lot_no,
          COALESCE(SUM(CASE WHEN m.move_date >= ? AND m.move_date < DATE_ADD(?, INTERVAL 1 DAY) AND m.qty > 0 THEN m.qty END), 0) qty_in,
          COALESCE(-SUM(CASE WHEN m.move_date >= ? AND m.move_date < DATE_ADD(?, INTERVAL 1 DAY) AND m.qty < 0 THEN m.qty END), 0) qty_out,
          COALESCE(SUM(CASE WHEN m.move_date < DATE_ADD(?, INTERVAL 1 DAY) THEN m.qty END), 0) bal,
          (SELECT COUNT(*) FROM packages p LEFT JOIN items pi ON pi.id = p.pack_item_id
            WHERE p.finished_item_id = i.id AND p.status = 'in_stock' AND (pi.name IS NULL OR pi.name NOT LIKE '%شكار%')) cartons,
          (SELECT COUNT(*) FROM packages p JOIN items pi ON pi.id = p.pack_item_id
            WHERE p.finished_item_id = i.id AND p.status = 'in_stock' AND pi.name LIKE '%شكار%') sacks
        FROM items i LEFT JOIN stock_moves m ON m.item_id = i.id WHERE (i.active = 1 OR EXISTS (SELECT 1 FROM stock_moves x WHERE x.item_id = i.id))";
$params = [$from, $to, $from, $to, $to];
if ($type !== '') { $sql .= ' AND i.type = ?'; $params[] = $type; }
if ($lot !== '') { $sql .= ' AND i.lot_no = ?'; $params[] = $lot; }
if ($search !== '') { $sql .= ' AND (i.code LIKE ? OR i.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " GROUP BY i.id ORDER BY FIELD(i.type,'raw','cone','pack','finished','other'), i.code";
$all = q($sql, $params)->fetchAll();

if ($pkFilter) {
    // balance / in / out of the finished yarn rebuilt from the matching cartons: made in the period, sold (or returned) on an invoice dated in the period
    $pf = ($pk === 'sack' ? " AND pi.name LIKE '%شكار%'" : ($pk === 'carton' ? " AND (pi.name IS NULL OR pi.name NOT LIKE '%شكار%')" : ''))
        . ($md === 'mixed' ? ' AND p.is_mixed=1' : ($md === 'std' ? ' AND p.is_mixed=0' : ''));
    $made = q("SELECT p.finished_item_id id,
                 COALESCE(SUM(CASE WHEN p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY) THEN p.net END),0) qin,
                 COALESCE(SUM(CASE WHEN p.created_at < DATE_ADD(?, INTERVAL 1 DAY) THEN p.net END),0) qtot,
                 COALESCE(SUM(p.status='in_stock' AND (pi.name IS NULL OR pi.name NOT LIKE '%شكار%')),0) cartons,
                 COALESCE(SUM(p.status='in_stock' AND pi.name LIKE '%شكار%'),0) sacks
               FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id WHERE p.status<>'void'$pf GROUP BY p.finished_item_id", [$from, $to, $to])->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $sold = q("SELECT p.finished_item_id id,
                 COALESCE(SUM(CASE WHEN s.inv_date >= ? AND s.inv_date <= ? THEN IF(s.type='sale', p.net, -p.net) END),0) qout,
                 COALESCE(SUM(CASE WHEN s.inv_date <= ? THEN IF(s.type='sale', p.net, -p.net) END),0) qtot
               FROM sales_packages sp JOIN sales_invoices s ON s.id=sp.invoice_id AND s.voided=0 JOIN packages p ON p.id=sp.package_id
               LEFT JOIN items pi ON pi.id=p.pack_item_id WHERE 1=1$pf GROUP BY p.finished_item_id", [$from, $to, $to])->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    foreach ($all as &$r) {
        if ($r['type'] !== 'finished') continue;
        $id = (int)$r['id'];
        $r['qty_in'] = (float)($made[$id]['qin'] ?? 0);
        $r['qty_out'] = (float)($sold[$id]['qout'] ?? 0);
        $r['bal'] = (float)($made[$id]['qtot'] ?? 0) - (float)($sold[$id]['qtot'] ?? 0);
        $r['cartons'] = (int)($made[$id]['cartons'] ?? 0);
        $r['sacks'] = (int)($made[$id]['sacks'] ?? 0);
    }
    unset($r);
}

// quick summary over the selected type (before the "show" filter); carton counts are live, so only meaningful up to today
$sum = ['finished_kg' => 0.0, 'finished_cartons' => 0, 'finished_sacks' => 0, 'produced' => 0.0, 'raw_kg' => 0.0, 'cones' => 0.0, 'packs' => []];
foreach ($all as $r) {
    if ($r['type'] === 'finished') { $sum['finished_kg'] += $r['bal']; $sum['finished_cartons'] += (int)$r['cartons']; $sum['finished_sacks'] += (int)$r['sacks']; $sum['produced'] += $r['qty_in']; }
    elseif ($r['type'] === 'raw') $sum['raw_kg'] += $r['bal'];
    elseif ($r['type'] === 'cone') $sum['cones'] += $r['bal'];
    elseif ($r['type'] === 'pack') $sum['packs'][] = [$r['name'], $r['bal']];
}
$rows = array_values(array_filter($all, fn($r) => match ($show) {
    'pos' => $r['bal'] > 0.0005, 'neg' => $r['bal'] < -0.0005, 'zero' => abs($r['bal']) <= 0.0005, default => true,
}));
$d = fn(array $r) => $r['unit'] === 'kg' ? 3 : 0;
$title = 'جرد المخزن';
$fileName = $title . ' من ' . $from . ' إلى ' . $to . ($type ? ' - ' . ITEM_TYPES[$type] : '');

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"stock.csv\"; filename*=UTF-8''" . rawurlencode($fileName . '.csv'));
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['الكود', 'الصنف', 'النوع', 'اللوط', 'الكمية الداخلة', 'الكمية الخارجة', 'رصيد الفترة', 'الرصيد الكلي', 'الوحدة', 'كراتين في المخزن', 'شكاير في المخزن']);
    foreach ($rows as $r) {
        fputcsv($o, [$r['code'], $r['name'], ITEM_TYPES[$r['type']], $r['lot_no'], round($r['qty_in'], 3), round($r['qty_out'], 3), round($r['qty_in'] - $r['qty_out'], 3),
            round($r['bal'], 3), UNITS[$r['unit']], $r['type'] === 'finished' ? (int)$r['cartons'] : '', $r['type'] === 'finished' ? (int)$r['sacks'] : '']);
    }
    exit;
}

$logo = setting('logo_file');
$pageTitle = $title;
$qs = http_build_query(array_filter(['type' => $type, 'from' => $from, 'to' => $to, 'show' => $show, 'flat' => $grouped ? '' : '1', 'pk' => $pk, 'md' => $md, 'lot' => $lot, 's' => $search], fn($v) => $v !== ''));
require __DIR__ . '/inc/header.php';
?>
<style>
.inv-sheet { position: relative; }
.sheet-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: .6rem; }
.sheet-head img { height: 78px; }
.sheet-co { text-align: right; line-height: 1.5; }
.sheet-co .co { font-size: 1.3rem; font-weight: 800; }
.sheet-title { text-align: center; margin: .2rem auto .8rem; }
.sheet-title span { display: inline-block; background: #d0d3d8; border: 1px solid #333; padding: .15rem 3rem; font-size: 1.5rem; font-weight: 800; }
.sheet-meta { width: 100%; border-collapse: collapse; margin-bottom: .5rem; }
.sheet-meta td { padding: .2rem .4rem; width: 33%; }
.sheet-meta .lbl { color: #222; }
table.inv { width: 100%; border-collapse: collapse; }
table.inv th { background: #d0d3d8; border: 1px solid #333; padding: .3rem .5rem; text-align: center; }
table.inv td { border: 1px solid #333; padding: .25rem .5rem; text-align: center; }
table.inv td.name { text-align: right; }
table.inv tr.grp td { background: #eef0f3; font-weight: 800; text-align: right; }
table.inv tr.sub td { font-weight: 800; background: #f6f7f9; }
.sum-box { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .6rem; }
.sum-box div { border: 1px solid #333; padding: .3rem .8rem; min-width: 150px; text-align: center; }
.sum-box b { display: block; font-size: 1.1rem; }
@page { size: A4; margin: 0; }
@media print {
  body { margin: 0; color: #000 !important; }
  .card.inv-sheet { padding: 12mm 10mm !important; box-shadow: none; }
  .inv-sheet, .inv-sheet * { color: #000 !important; opacity: 1 !important; }
  .inv-sheet { font-weight: 600; } .inv-sheet b, .inv-sheet th, .inv-sheet .co { font-weight: 800; }
  .sheet-title span, table.inv th { background: #d0d3d8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.inv tr.grp td, table.inv tr.sub td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  table.inv thead { display: table-header-group; } table.inv tr { page-break-inside: avoid; }
}
</style>
<div class="page-head no-print"><h4><i class="bi bi-clipboard-data"></i> <?= $title ?></h4><a href="stock_lot.php" class="btn btn-sm btn-primary"><i class="bi bi-diagram-3"></i> جرد بالنمرة / اللوط</a></div>
<form class="card p-3 mb-3 no-print" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">رقم اللوط</label><select name="lot" class="form-select"><option value="">كل اللوطات</option>
      <?php foreach ($lots as $l): ?><option value="<?= e($l['lot_no']) ?>"<?= $lot === $l['lot_no'] ? ' selected' : '' ?>><?= e($l['lot_no'] . ($l['spec'] ? ' — ' . $l['spec'] : '') . (($l['sold'] > 0.0005 && abs($l['bal']) < 0.0005) ? ' ✔ اتباع بالكامل' : '')) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">النمرة / اسم الصنف</label><input name="s" class="form-control" value="<?= e($search) ?>" placeholder="مثال: 1303014 أو 300/96"></div>
    <div class="col-md-2"><label class="form-label">النوع</label><select name="type" class="form-select"><option value="">كل الأنواع (خام + تام + كونات + عبوات)</option>
      <?php foreach (ITEM_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
    <div class="col-md-2"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
    <div class="col-md-3"><label class="form-label">إظهار</label><select name="show" class="form-select"><?php foreach (SHOW_LABELS as $k => $l): ?><option value="<?= $k ?>"<?= $show === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="flat" value="1"<?= $grouped ? '' : ' checked' ?>> من غير تجميع بالنوع</label></div>
    <div class="col-md-3"><label class="form-label">التعبئة (خيط تام)</label><select name="pk" class="form-select"><option value="">كراتين وشكاير</option>
      <?php foreach (PK_LABELS as $k => $l): ?><option value="<?= $k ?>"<?= $pk === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">نوع الإنتاج (خيط تام)</label><select name="md" class="form-select"><option value="">نظامي ومتعدد</option>
      <?php foreach (MD_LABELS as $k => $l): ?><option value="<?= $k ?>"<?= $md === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php if ($pkFilter): ?><div class="col-md-6 small text-muted align-self-center">التصفية دي بتعرض الخيط التام بس، والأرقام محسوبة من الكراتين نفسها (إنتاج وبيع بتاريخ الفاتورة).</div><?php endif; ?>
    <div class="col-12 d-flex gap-2 flex-wrap">
      <button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
      <button type="button" onclick="print()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> طباعة</button>
      <a class="btn btn-outline-dark" href="stock_pdf.php?<?= e($qs) ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
      <a class="btn btn-outline-success" href="stock.php?<?= e($qs) ?>&csv=1"><i class="bi bi-file-earmark-excel"></i> إكسل</a>
    </div>
  </div>
</form>
<div class="card p-4 inv-sheet">
  <div class="sheet-head">
    <div class="sheet-co"><div class="co"><?= e(setting('company_name')) ?></div><div><?= e(setting('company_address')) ?></div>
      <div class="num"><?= e(setting('company_phone1')) ?></div><div class="num"><?= e(setting('company_phone2')) ?></div></div>
    <div><?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><img src="assets/brand.svg" alt=""><?php endif; ?></div>
  </div>
  <div class="sheet-title"><span><?= $title ?></span></div>
  <table class="sheet-meta"><tr>
    <td><span class="lbl">من تاريخ :</span> <b class="num"><?= e(date('d/m/Y', strtotime($from))) ?></b></td>
    <td><span class="lbl">إلى تاريخ :</span> <b class="num"><?= e(date('d/m/Y', strtotime($to))) ?></b></td>
    <td><span class="lbl">النوع :</span> <b><?= $type ? e(ITEM_TYPES[$type]) : 'الكل' ?></b></td>
  </tr><tr>
    <td colspan="3"><span class="lbl">الإظهار :</span> <b><?= e(SHOW_LABELS[$show]) ?></b> — <span class="lbl">المستودع :</span> <b>المستودع الرئيسي</b><?php if ($pkFilter): ?> — <span class="lbl">التصفية :</span> <b><?= e(implode(' / ', array_filter([PK_LABELS[$pk] ?? '', MD_LABELS[$md] ?? '']))) ?></b><?php endif; ?></td>
  </tr></table>
  <div class="sum-box">
    <?php if ($type === '' || $type === 'finished'): ?><div>إنتاج الفترة (خيط تام)<b class="num"><?= fmt_qty($sum['produced'], 3) ?> كيلو</b></div>
    <div>خيط تام<b class="num"><?= fmt_qty($sum['finished_kg'], 3) ?> كيلو</b><?php if ($to === $today): ?><small><?= (int)$sum['finished_cartons'] ?> كرتونة<?= $sum['finished_sacks'] ? ' + ' . (int)$sum['finished_sacks'] . ' شكارة' : '' ?> في المخزن</small><?php endif; ?></div><?php endif; ?>
    <?php if ($type === '' || $type === 'raw'): ?><div>خام POY<b class="num"><?= fmt_qty($sum['raw_kg'], 3) ?> كيلو</b></div><?php endif; ?>
    <?php if ($type === '' || $type === 'cone'): ?><div>الكونات<b class="num"><?= fmt_qty($sum['cones'], 0) ?> قطعة</b></div><?php endif; ?>
    <?php if ($type === '' || $type === 'pack'): foreach ($sum['packs'] as [$pn, $pq]): ?><div><?= e($pn) ?><b class="num"><?= fmt_qty($pq, 0) ?> قطعة</b></div><?php endforeach; endif; ?>
  </div>
  <table class="inv">
    <thead><tr><th>#</th><th>رقم المادة</th><th>اسم المادة</th><th>الكمية الداخلة</th><th>الكمية الخارجة</th><th>رصيد الفترة</th><th>الرصيد الكلي</th><th>الوحدة</th><?php if ($type === '' || $type === 'finished'): ?><th>كراتين</th><th>شكاير</th><?php endif; ?></tr></thead>
    <?php
    $cols = ($type === '' || $type === 'finished') ? 10 : 8;
    $n = 0; $cur = null; $sub = ['in' => 0, 'out' => 0, 'bal' => 0, 'c' => 0, 's' => 0]; $tot = [];
    $flush = function () use (&$sub, &$cur, $cols, &$tot) {
        if ($cur === null) return;
        echo '<tr class="sub"><td colspan="3" style="text-align:right">إجمالي ' . e(ITEM_TYPES[$cur]) . '</td><td class="num">' . fmt_qty($sub['in'], 3) . '</td><td class="num">' . fmt_qty($sub['out'], 3) . '</td><td class="num">' . fmt_qty($sub['in'] - $sub['out'], 3) . '</td><td class="num">' . fmt_qty($sub['bal'], 3) . '</td><td></td>' . ($cols === 10 ? '<td>' . ($cur === 'finished' ? $sub['c'] : '') . '</td><td>' . ($cur === 'finished' ? $sub['s'] : '') . '</td>' : '') . '</tr>';
        $sub = ['in' => 0, 'out' => 0, 'bal' => 0, 'c' => 0, 's' => 0];
    };
    foreach ($rows as $r):
        if ($grouped && $r['type'] !== $cur) { $flush(); $cur = $r['type']; echo '<tr class="grp"><td colspan="' . $cols . '">' . e(ITEM_TYPES[$cur]) . '</td></tr>'; }
        $n++; $dd = $d($r);
        $sub['in'] += $r['qty_in']; $sub['out'] += $r['qty_out']; $sub['bal'] += $r['bal']; $sub['c'] += (int)$r['cartons']; $sub['s'] += (int)$r['sacks'];
        $tot[$r['unit']] = ($tot[$r['unit']] ?? 0) + $r['bal'];
    ?>
      <tr><td><?= $n ?></td><td class="num"><?= e($r['code']) ?></td><td class="name"><?= e($r['name']) ?></td>
        <td class="num"><?= fmt_qty($r['qty_in'], $dd) ?></td><td class="num"><?= fmt_qty($r['qty_out'], $dd) ?></td><td class="num"><?= fmt_qty($r['qty_in'] - $r['qty_out'], $dd) ?></td>
        <td class="num fw-bold <?= $r['bal'] < -0.0005 ? 'neg' : '' ?>"><?= fmt_qty($r['bal'], $dd) ?></td><td><?= UNITS[$r['unit']] ?></td>
        <?php if ($cols === 10): ?><td><?= $r['type'] === 'finished' ? (int)$r['cartons'] : '' ?></td><td><?= $r['type'] === 'finished' ? (int)$r['sacks'] : '' ?></td><?php endif; ?></tr>
    <?php endforeach; if ($grouped) $flush(); ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $cols ?>">مفيش مواد بالفلاتر دي</td></tr><?php endif; ?>
  </table>
  <div class="mt-2 fw-bold">إجمالي الأرصدة: <span class="num"><?= fmt_qty($tot['kg'] ?? 0, 3) ?></span> كيلو<?= isset($tot['pcs']) ? ' — <span class="num">' . fmt_qty($tot['pcs'], 0) . '</span> قطعة' : '' ?></div>
</div>
<?php require __DIR__ . '/inc/footer.php';
