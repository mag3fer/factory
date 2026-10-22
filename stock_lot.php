<?php
// Inventory by count / lot (جرد بالنمرة واللوط): for each item in the period — opening balance, received, produced,
// consumed by production, sold (net of returns), other adjustments and closing balance. Items that share a lot
// (raw POY + the finished counts made from it) are grouped, so one lot shows the whole story: raw in / used / sold, yarn made / sold.
require_once __DIR__ . '/inc/bootstrap.php';
require_role('store');

$validDate = fn(string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
$from = $validDate('from', date('Y-01-01'));
$to = $validDate('to', date('Y-m-d'));
if ($from > $to) [$from, $to] = [$to, $from];
$lot = trim((string)($_GET['lot'] ?? ''));
$search = trim((string)($_GET['s'] ?? ''));
$type = isset(ITEM_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
// finished yarn only: كراتين / شكاير and نظامي / متعدد — rebuilt from the cartons themselves
$pk = in_array($_GET['pk'] ?? '', ['carton', 'sack'], true) ? $_GET['pk'] : '';
$md = in_array($_GET['md'] ?? '', ['std', 'mixed'], true) ? $_GET['md'] : '';
$pkFilter = $pk !== '' || $md !== '';
if ($pkFilter) $type = 'finished';

$lots = q("SELECT i.lot_no, COUNT(DISTINCT i.id) n, COALESCE(MAX(NULLIF(i.spec, '')), MAX(NULLIF(i.poy_spec, ''))) spec,
                   COALESCE(SUM(m.qty), 0) bal, COALESCE(-SUM(CASE WHEN m.source = 'sale' THEN m.qty END), 0) sold
            FROM items i JOIN stock_moves m ON m.item_id = i.id WHERE i.lot_no <> '' AND i.unit = 'kg' GROUP BY i.lot_no ORDER BY i.lot_no")->fetchAll();   // only lots that moved; bal = kg left in the whole lot

$inP = "m.move_date >= ? AND m.move_date < DATE_ADD(?, INTERVAL 1 DAY)";
$sql = "SELECT i.id, i.code, i.name, i.type, i.unit, i.lot_no, i.active,
          COALESCE(SUM(CASE WHEN m.move_date < ? THEN m.qty END), 0) opening,
          COALESCE(SUM(CASE WHEN $inP AND m.source='doc' AND m.qty>0 THEN m.qty END), 0) received,
          COALESCE(SUM(CASE WHEN $inP AND m.source='package' AND m.qty>0 THEN m.qty END), 0) produced,
          COALESCE(-SUM(CASE WHEN $inP AND m.source='package' AND m.qty<0 THEN m.qty END), 0) consumed,
          COALESCE(-SUM(CASE WHEN $inP AND m.source='sale' THEN m.qty END), 0) sold,
          COALESCE(SUM(CASE WHEN $inP AND m.source='doc' AND m.qty<0 THEN m.qty END), 0) other,
          COALESCE(SUM(CASE WHEN m.move_date < DATE_ADD(?, INTERVAL 1 DAY) THEN m.qty END), 0) closing
        FROM items i LEFT JOIN stock_moves m ON m.item_id = i.id WHERE (i.active = 1 OR EXISTS (SELECT 1 FROM stock_moves x WHERE x.item_id = i.id))";
$params = [$from, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $to];
if ($lot !== '') { $sql .= ' AND i.lot_no = ?'; $params[] = $lot; }
if ($type !== '') { $sql .= ' AND i.type = ?'; $params[] = $type; }
if ($search !== '') { $sql .= ' AND (i.code LIKE ? OR i.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= " GROUP BY i.id ORDER BY i.lot_no, FIELD(i.type,'raw','cone','pack','finished','other'), i.code";
$all = q($sql, $params)->fetchAll();
if ($pkFilter) {
    $pf = ($pk === 'sack' ? " AND pi.name LIKE '%شكار%'" : ($pk === 'carton' ? " AND (pi.name IS NULL OR pi.name NOT LIKE '%شكار%')" : ''))
        . ($md === 'mixed' ? ' AND p.is_mixed=1' : ($md === 'std' ? ' AND p.is_mixed=0' : ''));
    $made = q("SELECT p.finished_item_id id,
                 COALESCE(SUM(CASE WHEN p.created_at < ? THEN p.net END),0) before_in,
                 COALESCE(SUM(CASE WHEN p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY) THEN p.net END),0) qin,
                 COALESCE(SUM(CASE WHEN p.created_at < DATE_ADD(?, INTERVAL 1 DAY) THEN p.net END),0) qtot
               FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id WHERE p.status<>'void'$pf GROUP BY p.finished_item_id", [$from, $from, $to, $to])->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $soldQ = q("SELECT p.finished_item_id id,
                 COALESCE(SUM(CASE WHEN s.inv_date < ? THEN IF(s.type='sale', p.net, -p.net) END),0) before_out,
                 COALESCE(SUM(CASE WHEN s.inv_date >= ? AND s.inv_date <= ? THEN IF(s.type='sale', p.net, -p.net) END),0) qout,
                 COALESCE(SUM(CASE WHEN s.inv_date <= ? THEN IF(s.type='sale', p.net, -p.net) END),0) qtot
               FROM sales_packages sp JOIN sales_invoices s ON s.id=sp.invoice_id AND s.voided=0 JOIN packages p ON p.id=sp.package_id
               LEFT JOIN items pi ON pi.id=p.pack_item_id WHERE 1=1$pf GROUP BY p.finished_item_id", [$from, $from, $to, $to])->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    foreach ($all as &$r) {
        $id = (int)$r['id'];
        $r['opening'] = (float)($made[$id]['before_in'] ?? 0) - (float)($soldQ[$id]['before_out'] ?? 0);
        $r['received'] = 0; $r['consumed'] = 0; $r['other'] = 0;
        $r['produced'] = (float)($made[$id]['qin'] ?? 0);
        $r['sold'] = (float)($soldQ[$id]['qout'] ?? 0);
        $r['closing'] = (float)($made[$id]['qtot'] ?? 0) - (float)($soldQ[$id]['qtot'] ?? 0);
    }
    unset($r);
}
$rows = array_values(array_filter($all, function ($r) {
    foreach (['opening', 'received', 'produced', 'consumed', 'sold', 'other', 'closing'] as $k) if (abs((float)$r[$k]) > 0.0005) return true;
    return false;
}));

$csvCols = ['lot_no' => 'اللوط', 'code' => 'الكود', 'name' => 'الصنف', 'opening' => 'رصيد أول المدة', 'received' => 'استلام / افتتاحي', 'produced' => 'إنتاج',
    'consumed' => 'استهلاك في الإنتاج', 'sold' => 'مبيعات (صافي المرتجع)', 'other' => 'صرف / تسويات', 'closing' => 'الرصيد'];
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"stock_lot.csv\"");
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, array_values($csvCols));
    foreach ($rows as $r) fputcsv($o, array_map(fn($k) => in_array($k, ['lot_no', 'code', 'name']) ? $r[$k] : round((float)$r[$k], 3), array_keys($csvCols)));
    exit;
}

$pageTitle = 'جرد بالنمرة / اللوط';
require __DIR__ . '/inc/header.php';
$num = fn($v, $r) => abs((float)$v) < 0.0005 ? '<span class="text-muted">—</span>' : fmt_qty((float)$v, $r['unit'] === 'kg' ? 3 : 0);
?>
<div class="page-head no-print"><h4><i class="bi bi-diagram-3"></i> جرد بالنمرة / اللوط</h4>
  <a href="stock.php" class="btn btn-sm btn-outline-secondary">← جرد المخزن</a></div>
<form class="card p-3 mb-3 no-print" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">رقم اللوط</label>
      <select name="lot" class="form-select"><option value="">كل اللوطات</option>
        <?php foreach ($lots as $l): ?><option value="<?= e($l['lot_no']) ?>"<?= $lot === $l['lot_no'] ? ' selected' : '' ?>><?= e($l['lot_no'] . ($l['spec'] ? ' — ' . $l['spec'] : '')) ?> (<?= (int)$l['n'] ?> صنف)<?= ($l['sold'] > 0.0005 && abs($l['bal']) < 0.0005) ? ' ✔ اتباع بالكامل' : '' ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">النمرة / اسم الصنف</label><input name="s" class="form-control" value="<?= e($search) ?>" placeholder="مثال: 1303014 أو 300/96"></div>
    <div class="col-md-2"><label class="form-label">النوع</label><select name="type" class="form-select"><option value="">كل الأنواع</option>
      <?php foreach (ITEM_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">التعبئة (خيط تام)</label><select name="pk" class="form-select"><option value="">كراتين وشكاير</option><option value="carton"<?= $pk === 'carton' ? ' selected' : '' ?>>كراتين بس</option><option value="sack"<?= $pk === 'sack' ? ' selected' : '' ?>>شكاير بس</option></select></div>
    <div class="col-md-2"><label class="form-label">نوع الإنتاج (خيط تام)</label><select name="md" class="form-select"><option value="">نظامي ومتعدد</option><option value="std"<?= $md === 'std' ? ' selected' : '' ?>>نظامي بس</option><option value="mixed"<?= $md === 'mixed' ? ' selected' : '' ?>>متعدد بس</option></select></div>
    <div class="col-md-2"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
    <div class="col-md-2"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
    <button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i> طباعة</button>
    <a class="btn btn-dark" id="lotRep" href="lot_report.php"><i class="bi bi-file-earmark-text"></i> كشف اللوط للطباعة</a>
    <a class="btn btn-outline-success" href="?<?= e(http_build_query(array_filter(['lot' => $lot, 's' => $search, 'type' => $type, 'pk' => $pk, 'md' => $md, 'from' => $from, 'to' => $to]) + ['csv' => 1])) ?>"><i class="bi bi-file-earmark-excel"></i> إكسل</a>
  </div>
</form>
<div class="card p-3">
  <div class="mb-2 fw-bold">جرد بالنمرة / اللوط — من <?= e($from) ?> إلى <?= e($to) ?><?= $lot !== '' ? ' — لوط ' . e($lot) : '' ?></div>
  <div class="table-responsive">
  <table class="table table-sm table-bordered text-center align-middle mb-0">
    <thead class="table-light"><tr><th>النمرة</th><th>الصنف</th><th>رصيد أول المدة</th><th>استلام / افتتاحي</th><th>إنتاج</th><th>استهلاك في الإنتاج</th><th>مبيعات</th><th>صرف / تسويات</th><th>الرصيد</th></tr></thead>
    <?php
    $cur = null;
    $flush = function () use (&$cur) {};   // no lot total: raw and yarn kilos would be added together and double count
    foreach ($rows as $r):
        if ($r['lot_no'] !== $cur) {
            $flush(); $cur = $r['lot_no'];
            echo '<tr class="table-light fw-bold"><td colspan="9" class="text-end">' . ($cur !== '' ? 'لوط ' . e($cur) : 'بدون لوط') . '</td></tr>';
        }
    ?>
      <tr>
        <td class="num"><?= e($r['code']) ?></td>
        <td class="text-end"><a href="item_card.php?id=<?= (int)$r['id'] ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"><?= e($r['name']) ?></a> <span class="badge bg-light text-dark border"><?= e(ITEM_TYPES[$r['type']]) ?></span><?= $r['active'] ? '' : ' <span class="badge bg-secondary">قديم / غير نشط</span>' ?></td>
        <td class="num"><?= $num($r['opening'], $r) ?></td><td class="num"><?= $num($r['received'], $r) ?></td>
        <td class="num"><?= $num($r['produced'], $r) ?></td><td class="num"><?= $num($r['consumed'], $r) ?></td>
        <td class="num"><?= $num($r['sold'], $r) ?></td><td class="num"><?= $num($r['other'], $r) ?></td>
        <td class="num fw-bold"><?= $num($r['closing'], $r) ?></td>
      </tr>
    <?php endforeach; $flush(); ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="text-muted">مفيش حركة بالفلاتر دي</td></tr><?php endif; ?>
  </table>
  </div>
  <div class="small text-muted mt-2">لما تختار كراتين/شكاير أو نظامي/متعدد بيظهر الخيط التام بس (الإنتاج والمبيعات من الكراتين نفسها). المبيعات = اللي اتباع صافي المرتجع (خام وخيط تام). استهلاك الإنتاج = الخام والكونات والعبوات اللي اتصرفت وقت تسجيل الكراتين.</div>
</div>
<script>
(function () {
  const sel = document.querySelector('select[name=lot]'), a = document.getElementById('lotRep');
  const sync = () => { a.href = 'lot_report.php' + (sel.value ? '?lot=' + encodeURIComponent(sel.value) : ''); };
  sel.addEventListener('change', sync); sync();
})();
</script>
<?php require __DIR__ . '/inc/footer.php';
