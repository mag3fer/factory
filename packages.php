<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('store', 'scale');

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$itemId = (int)($_GET['item'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['in_stock', 'sold', 'void'], true) ? $_GET['status'] : '';
$search = trim($_GET['q'] ?? '');

$where = []; $params = [];
if ($search !== '') { $where[] = 'p.serial = ?'; $params[] = $search; }
else {
    $where[] = 'p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; array_push($params, $from, $to);
    if ($itemId) { $where[] = 'p.finished_item_id=?'; $params[] = $itemId; }
    if ($status) { $where[] = 'p.status=?'; $params[] = $status; }
}
$rows = q('SELECT p.*, i.code, i.name, us.full_name usr, c.name cone FROM packages p JOIN items i ON i.id=p.finished_item_id JOIN users us ON us.id=p.user_id
           LEFT JOIN items c ON c.id=p.cone_item_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 1000', $params)->fetchAll();
$finished = items_by_type('finished');
$canVoid = in_array($u['role'], ['admin', 'store'], true);
const PKG_STATUS = ['in_stock' => 'في المخزن', 'sold' => 'مباع', 'void' => 'ملغي'];

$pageTitle = 'الكراتين المنتجة';
require __DIR__ . '/inc/header.php';
?>
<h4>الكراتين / الشكاير المنتجة</h4>
<form class="row g-2 mb-2 no-print" id="filters">
  <div class="col-auto">من <input type="date" name="from" class="form-control d-inline w-auto" value="<?= e($from) ?>"></div>
  <div class="col-auto">إلى <input type="date" name="to" class="form-control d-inline w-auto" value="<?= e($to) ?>"></div>
  <div class="col-auto"><select name="item" class="form-select"><option value="">كل النمر</option>
    <?php foreach ($finished as $f): ?><option value="<?= $f['id'] ?>"<?= $itemId === (int)$f['id'] ? ' selected' : '' ?>><?= e($f['code'] . ' — ' . $f['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><select name="status" class="form-select"><option value="">كل الحالات</option>
    <?php foreach (PKG_STATUS as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><input name="q" class="form-control num" placeholder="امسح الباركود" value="<?= e($search) ?>"></div>
  <div class="col-auto"><button class="btn btn-primary">عرض</button> <button type="button" onclick="print()" class="btn btn-outline-secondary">طباعة</button></div>
</form>
<?php
$sumNet = 0; $n = 0;
$sumGross = 0; $sumCones = 0; $nc = 0;   // $nc = cartons with cones (cartons without cones have no ideal weight)
$perCone = (float)setting('target_gross_per_cone', '6'); $okPct = (float)setting('target_ok_pct', '3'); $warnPct = (float)setting('target_warn_pct', '6');
foreach ($rows as $r) if ($r['status'] !== 'void') {
    $sumNet += $r['net']; $n++;
    if ((int)$r['cones_count'] > 0) { $sumGross += $r['gross']; $sumCones += (int)$r['cones_count']; $nc++; }
}
$avgGross = $nc ? $sumGross / $nc : 0; $avgCones = $nc ? $sumCones / $nc : 0;
$avgTarget = $avgCones * $perCone;
$diff = $avgGross - $avgTarget;
$diffPct = $avgTarget > 0 ? $diff / $avgTarget * 100 : 0;
$lvl = abs($diffPct) <= $okPct ? 'ok' : (abs($diffPct) <= $warnPct ? 'warn' : 'bad');
$dotPos = max(0, min(100, 50 + $diffPct / ($warnPct * 2) * 50));
$rowLvl = function (array $r) use ($perCone, $okPct, $warnPct): string {
    $t = (int)$r['cones_count'] * $perCone;
    if ($t <= 0 || $r['status'] === 'void') return '';
    $p = abs($r['gross'] - $t) / $t * 100;
    return $p <= $okPct ? 'ok' : ($p <= $warnPct ? 'warn' : 'bad');
};
?>
<p class="mb-2">العدد: <b><?= $n ?></b> — إجمالي الصافي: <b class="num"><?= fmt_qty($sumNet) ?></b> كيلو</p>
<?php if ($nc): ?>
<div class="card p-3 mb-2 no-print">
  <div class="row text-center g-2 align-items-center">
    <div class="col-6 col-md-3"><div class="text-muted small">متوسط وزن الكرتونة (قائم)</div><div class="fs-3 fw-bold num"><?= fmt_qty($avgGross, 2) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">متوسط الصافي</div><div class="fs-3 fw-bold text-success num"><?= fmt_qty($sumNet / $n, 2) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">متوسط الكونات</div><div class="fs-3 fw-bold num"><?= fmt_qty($avgCones, 1) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">المثالي (قائم) للمتوسط ده</div><div class="fs-3 fw-bold num"><?= fmt_qty($avgTarget, 2) ?></div></div>
  </div>
  <div class="target-bar mt-3 <?= $lvl ?>">
    <div class="tb-text"><span>الإنتاج <?= $lvl === 'ok' ? 'مظبوط' : ($diff < 0 ? 'أخف من المثالي' : 'أتقل من المثالي') ?> (المثالي <?= fmt_qty($perCone, 0) ?> كيلو قائم لكل كونة)</span><b dir="ltr"><?= ($diff >= 0 ? '+' : '') . number_format($diff, 2) ?> kg (<?= ($diff >= 0 ? '+' : '') . number_format($diffPct, 1) ?>%)</b></div>
    <div class="tb-track"><i class="tb-mid"></i><i class="tb-dot" style="left:<?= round($dotPos, 1) ?>%"></i></div>
  </div>
</div>
<?php endif; ?>
<div class="card p-0">
<table class="table table-sm table-hover mb-0" id="pk" data-csrf="<?= csrf_token() ?>">
  <thead class="table-light"><tr><th>الباركود</th><th>الكود</th><th>النمرة</th><th>الكونة</th><th>عدد</th><th>قائم</th><th>فوارغ</th><th>صافي</th><th>الوزن</th><th>الوقت</th><th>بواسطة</th><th>الحالة</th><th class="no-print"></th></tr></thead>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['status'] === 'void' ? 'text-decoration-line-through text-muted' : '' ?>">
      <td class="num"><?= e($r['serial']) ?></td><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><?= e($r['cone']) ?></td><td><?= (int)$r['cones_count'] ?></td>
      <td class="num"><?php if ($l = $rowLvl($r)): ?><i class="dev-dot <?= $l ?>" title="الفرق عن المثالي: <?= number_format($r['gross'] - $r['cones_count'] * $perCone, 2) ?> كيلو"></i> <?php endif; ?><?= fmt_qty($r['gross']) ?></td><td class="num"><?= fmt_qty($r['tare']) ?></td><td class="num fw-bold"><?= fmt_qty($r['net']) ?></td>
      <td><?= $r['weight_source'] === 'scale' ? 'ميزان' : 'يدوي' ?></td><td><?= e(substr($r['created_at'], 0, 16)) ?></td><td><?= e($r['usr']) ?></td>
      <td><?= PKG_STATUS[$r['status']] ?><?= $r['void_reason'] ? ' — ' . e($r['void_reason']) : '' ?></td>
      <td class="no-print text-nowrap"><a class="btn btn-sm btn-outline-secondary" target="_blank" href="label.php?id=<?= $r['id'] ?>">الستيكر</a>
        <?php if ($canVoid && $r['status'] === 'in_stock'): ?><button class="btn btn-sm btn-outline-danger void" data-id="<?= $r['id'] ?>" data-serial="<?= e($r['serial']) ?>">إلغاء</button><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="13" class="text-muted">مفيش</td></tr><?php endif; ?>
</table>
</div>
<script>
document.getElementById('pk').addEventListener('click', async e => {
  const b = e.target.closest('.void'); if (!b) return;
  const reason = prompt('سبب إلغاء الكرتونة ' + b.dataset.serial + ' (الكمية هتتشال من المخزن والكونات ترجع):');
  if (reason === null) return;
  const body = new URLSearchParams({ csrf: document.getElementById('pk').dataset.csrf, action: 'void', id: b.dataset.id, reason });
  const r = await (await fetch('api_package.php', { method: 'POST', body })).json();
  if (r.ok) location.reload(); else alert(r.error);
});
</script>
<?php require __DIR__ . '/inc/footer.php';
