<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('store', 'scale');

$today = date('Y-m-d');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $today;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : $today;
if ($from > $to) [$from, $to] = [$to, $from];
$itemId = (int)($_GET['item'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['in_stock', 'sold', 'void'], true) ? $_GET['status'] : '';
$search = trim($_GET['q'] ?? '');
$pk = in_array($_GET['pk'] ?? '', ['carton', 'sack'], true) ? $_GET['pk'] : '';      // كرتونة / شكارة
$md = in_array($_GET['md'] ?? '', ['std', 'mixed'], true) ? $_GET['md'] : '';        // نظامي / متعدد

$perCone = (float)setting('target_gross_per_cone', '6'); $okPct = (float)setting('target_ok_pct', '3'); $warnPct = (float)setting('target_warn_pct', '6');

// filters shared by the table and the statistics (the نظامي / متعدد split is applied separately)
$baseW = []; $baseP = [];
if ($search === '') {
    $baseW[] = 'p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; array_push($baseP, $from, $to);
    if ($itemId) { $baseW[] = 'p.finished_item_id=?'; $baseP[] = $itemId; }
    if ($status) { $baseW[] = 'p.status=?'; $baseP[] = $status; }
    if ($pk === 'sack') $baseW[] = "pi.name LIKE '%شكار%'";
    if ($pk === 'carton') $baseW[] = "(pi.name IS NULL OR pi.name NOT LIKE '%شكار%')";
} else {
    $baseW[] = 'p.serial = ?'; $baseP[] = $search;
}
$rowW = $baseW; $rowP = $baseP;
if ($search === '') { if ($md === 'std') $rowW[] = 'p.is_mixed=0'; if ($md === 'mixed') $rowW[] = 'p.is_mixed=1'; }
$rows = q('SELECT p.*, i.code, i.name, us.full_name usr, c.name cone, pl.serial pallet_serial, pl.capacity pallet_cap, (pi.name LIKE \'%شكار%\') is_sack
           FROM packages p JOIN items i ON i.id=p.finished_item_id JOIN users us ON us.id=p.user_id
           LEFT JOIN items c ON c.id=p.cone_item_id LEFT JOIN pallets pl ON pl.id=p.pallet_id LEFT JOIN items pi ON pi.id=p.pack_item_id
           WHERE ' . implode(' AND ', $rowW) . ' ORDER BY p.id DESC LIMIT 1000', $rowP)->fetchAll();
$tot = q("SELECT COUNT(*) n, COALESCE(SUM(p.net),0) net, COALESCE(SUM(p.cones_count),0) cones FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id
          WHERE p.status<>'void' AND " . implode(' AND ', $rowW), $rowP)->fetch();
$finished = items_by_type('finished');
$canVoid = in_array($u['role'], ['admin', 'store'], true);
const PKG_STATUS = ['in_stock' => 'في المخزن', 'sold' => 'مباع', 'void' => 'ملغي'];

// quality statistics, per type: the ideal weight is cones × target per cone, and نظامي (fixed cones) is judged apart from متعدد
// with no packing filter the quality is judged on cartons only: a sack is lighter (empty sack ≈ 0.08 kg vs carton ≈ 1.4 kg)
$qualW = $baseW;
if ($pk === '' && $search === '') $qualW[] = "(pi.name IS NULL OR pi.name NOT LIKE '%شكار%')";
$stat = function (int $mixed) use ($qualW, $baseP, $perCone, $okPct, $warnPct): array {
    $baseW = $qualW;
    $pc = sprintf('%.4F', $perCone); $ok = sprintf('%.4F', $okPct); $wn = sprintf('%.4F', $warnPct);
    $dev = "ABS(p.gross - p.cones_count*$pc) / (p.cones_count*$pc) * 100";
    $s = q("SELECT COUNT(*) n, COALESCE(SUM(p.net),0) net,
              COALESCE(SUM(CASE WHEN p.cones_count>0 THEN 1 END),0) nc,
              COALESCE(SUM(CASE WHEN p.cones_count>0 THEN p.gross END),0) gross_c,
              COALESCE(SUM(CASE WHEN p.cones_count>0 THEN p.cones_count END),0) cones_c,
              MIN(CASE WHEN p.cones_count>0 THEN p.gross END) gmin, MAX(CASE WHEN p.cones_count>0 THEN p.gross END) gmax,
              COALESCE(SUM(CASE WHEN p.cones_count>0 AND $dev <= $ok THEN 1 END),0) z_ok,
              COALESCE(SUM(CASE WHEN p.cones_count>0 AND $dev > $ok AND $dev <= $wn THEN 1 END),0) z_warn,
              COALESCE(SUM(CASE WHEN p.cones_count>0 AND $dev > $wn THEN 1 END),0) z_bad,
              COALESCE(SUM(pi.name LIKE '%شكار%'),0) sacks
            FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id
            WHERE p.status<>'void' AND p.is_mixed=?" . ($baseW ? ' AND ' . implode(' AND ', $baseW) : ''), array_merge([$mixed], $baseP))->fetch();
    $nc = (int)$s['nc'];
    $s['avg_gross'] = $nc ? $s['gross_c'] / $nc : 0;
    $s['avg_cones'] = $nc ? $s['cones_c'] / $nc : 0;
    $s['avg_net'] = (int)$s['n'] ? $s['net'] / $s['n'] : 0;
    $s['target'] = $s['avg_cones'] * $perCone;
    $s['diff'] = $s['avg_gross'] - $s['target'];
    $s['pct'] = $s['target'] > 0 ? $s['diff'] / $s['target'] * 100 : 0;
    $s['lvl'] = abs($s['pct']) <= $okPct ? 'ok' : (abs($s['pct']) <= $warnPct ? 'warn' : 'bad');
    $s['dot'] = max(0, min(100, 50 + $s['pct'] / ($warnPct * 2) * 50));
    // mixed cartons have no fixed cone count: they are judged per cone
    $s['per_cone'] = $s['cones_c'] > 0 ? $s['gross_c'] / $s['cones_c'] : 0;
    return $s;
};
$showStats = $search === '';
$sStd = $showStats ? $stat(0) : null;
$sMix = $showStats ? $stat(1) : null;

$rowLvl = function (array $r) use ($perCone, $okPct, $warnPct): string {
    $t = (int)$r['cones_count'] * $perCone;
    if ($t <= 0 || $r['status'] === 'void') return '';
    $p = abs($r['gross'] - $t) / $t * 100;
    return $p <= $okPct ? 'ok' : ($p <= $warnPct ? 'warn' : 'bad');
};

$cur = ['from' => $from, 'to' => $to, 'item' => $itemId ?: '', 'status' => $status, 'pk' => $pk, 'md' => $md];
$url = fn(array $o) => 'packages.php?' . http_build_query(array_filter(array_merge($cur, $o), fn($v) => $v !== '' && $v !== null));
$yesterday = date('Y-m-d', strtotime('-1 day'));
$ranges = ['اليوم' => [$today, $today], 'امبارح' => [$yesterday, $yesterday], 'آخر 7 أيام' => [date('Y-m-d', strtotime('-6 day')), $today], 'الشهر ده' => [date('Y-m-01'), $today]];

$gauge = function (array $s, string $title, string $basis) use ($perCone): void { ?>
  <div class="target-bar <?= $s['lvl'] ?>">
    <div class="tb-text"><span><?= $title ?>: <?= $s['lvl'] === 'ok' ? 'مظبوط' : ($s['diff'] < 0 ? 'أخف من المثالي' : 'أتقل من المثالي') ?> <small class="text-muted">(<?= $basis ?>)</small></span>
      <b dir="ltr"><?= ($s['diff'] >= 0 ? '+' : '') . number_format($s['diff'], 2) ?> kg (<?= ($s['diff'] >= 0 ? '+' : '') . number_format($s['pct'], 1) ?>%)</b></div>
    <div class="tb-track"><i class="tb-mid"></i><i class="tb-dot" style="left:<?= round($s['dot'], 1) ?>%"></i></div>
  </div>
<?php };
$zones = function (array $s): void {
    $t = max(1, (int)$s['z_ok'] + (int)$s['z_warn'] + (int)$s['z_bad']); ?>
  <div class="pk-zones" title="توزيع الكراتين حسب القرب من الوزن المثالي">
    <?php foreach ([['ok', 'مظبوط', (int)$s['z_ok']], ['warn', 'قريب', (int)$s['z_warn']], ['bad', 'بعيد', (int)$s['z_bad']]] as [$k, $l, $c]): if (!$c) continue; ?>
      <span class="z <?= $k ?>" style="width:<?= round($c / $t * 100, 1) ?>%"><?= $l ?> <b><?= $c ?></b> · <?= round($c / $t * 100) ?>%</span>
    <?php endforeach; ?>
  </div>
<?php };

$pageTitle = 'الكراتين المنتجة';
require __DIR__ . '/inc/header.php';
?>
<div class="pk-head">
  <div><h4 class="mb-0"><i class="bi bi-box-seam"></i> الكراتين / الشكاير المنتجة</h4>
    <div class="text-muted small mt-1"><?= $search !== '' ? 'نتيجة البحث عن الباركود' : 'من ' . e($from) . ' إلى ' . e($to) ?></div></div>
  <div class="no-print d-flex gap-2"><button type="button" onclick="print()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> طباعة</button></div>
</div>

<form class="card pk-filters no-print" id="filters" method="get">
  <input type="hidden" name="pk" value="<?= e($pk) ?>"><input type="hidden" name="md" value="<?= e($md) ?>">
  <div class="pk-chips">
    <?php foreach ($ranges as $l => [$a, $b]): ?><a class="chip<?= $from === $a && $to === $b && $search === '' ? ' on' : '' ?>" href="<?= e($url(['from' => $a, 'to' => $b])) ?>"><?= $l ?></a><?php endforeach; ?>
  </div>
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">من</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">إلى</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
    <div class="col-md-3"><label class="form-label small mb-1">النمرة</label><select name="item" class="form-select"><option value="">كل النمر</option>
      <?php foreach ($finished as $f): ?><option value="<?= $f['id'] ?>"<?= $itemId === (int)$f['id'] ? ' selected' : '' ?>><?= e($f['code'] . ' — ' . $f['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">الحالة</label><select name="status" class="form-select"><option value="">كل الحالات</option>
      <?php foreach (PKG_STATUS as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">باركود</label><input name="q" class="form-control num" placeholder="امسح الباركود" value="<?= e($search) ?>"></div>
    <div class="col-12 col-md-1"><button class="btn btn-primary w-100"><i class="bi bi-search"></i></button></div>
  </div>
  <div class="pk-segs">
    <div class="seg"><span>النوع</span>
      <?php foreach (['' => 'الكل', 'std' => 'نظامي', 'mixed' => 'متعدد'] as $k => $l): ?><a class="<?= $md === $k ? 'on' : '' ?><?= $k === 'mixed' ? ' mixed' : '' ?>" href="<?= e($url(['md' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
    <div class="seg"><span>التعبئة</span>
      <?php foreach (['' => 'الكل', 'carton' => 'كراتين', 'sack' => 'شكاير'] as $k => $l): ?><a class="<?= $pk === $k ? 'on' : '' ?>" href="<?= e($url(['pk' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
  </div>
</form>

<div class="row g-3 mb-3 pk-kpis">
  <div class="col-6 col-lg-3"><div class="card kpi"><div class="ic g-blue"><i class="bi bi-boxes"></i></div><div><div class="lbl">العدد</div><div class="value num"><?= (int)$tot['n'] ?></div><div class="sub"><?= (int)$tot['cones'] ?> كونة</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card kpi"><div class="ic g-green"><i class="bi bi-speedometer2"></i></div><div><div class="lbl">إجمالي الصافي</div><div class="value num"><?= fmt_qty($tot['net'], 1) ?></div><div class="sub">كيلو</div></div></div></div>
  <?php if ($showStats): ?>
  <div class="col-6 col-lg-3"><div class="card kpi"><div class="ic g-cyan"><i class="bi bi-check2-circle"></i></div><div><div class="lbl">نظامي</div><div class="value num"><?= (int)$sStd['n'] ?></div><div class="sub"><?= fmt_qty($sStd['net'], 1) ?> كيلو</div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card kpi"><div class="ic g-orange"><i class="bi bi-shuffle"></i></div><div><div class="lbl">متعدد</div><div class="value num"><?= (int)$sMix['n'] ?></div><div class="sub"><?= fmt_qty($sMix['net'], 1) ?> كيلو</div></div></div></div>
  <?php endif; ?>
</div>

<?php if ($showStats && $md !== 'mixed' && (int)$sStd['nc']): ?>
<div class="card pk-quality no-print">
  <div class="pk-q-head"><h6 class="mb-0"><i class="bi bi-clipboard-check"></i> تقييم الجودة — نظامي <?= $pk === 'sack' ? 'شكاير' : 'كراتين' ?> بس</h6>
    <span class="small text-muted">المتعدد مش داخل في المتوسط<?= (int)$sMix['n'] ? ' (' . (int)$sMix['n'] . ' كرتونة متعدد مستبعدة)' : '' ?></span></div>
  <div class="row text-center g-2 my-2">
    <div class="col-6 col-md-3"><div class="text-muted small">متوسط وزن <?= $pk === 'sack' ? 'الشكارة' : 'الكرتونة' ?> (قائم)</div><div class="fs-2 fw-bold num"><?= fmt_qty($sStd['avg_gross'], 2) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">المثالي (قائم)</div><div class="fs-2 fw-bold num text-secondary"><?= fmt_qty($sStd['target'], 2) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">متوسط الصافي</div><div class="fs-2 fw-bold text-success num"><?= fmt_qty($sStd['avg_net'], 2) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">أخف / أتقل <?= $pk === 'sack' ? 'شكارة' : 'كرتونة' ?> (قائم)</div><div class="fs-4 fw-bold num"><?= fmt_qty($sStd['gmin'], 2) ?> <span class="text-muted">—</span> <?= fmt_qty($sStd['gmax'], 2) ?></div></div>
  </div>
  <?php $gauge($sStd, 'الإنتاج النظامي', 'المثالي ' . fmt_qty($perCone, 0) . ' كيلو قائم لكل كونة'); ?>
  <?php $zones($sStd); ?>
  <?php if ($pk === ''): ?><div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> التقييم على الكراتين النظامي بس. لو عايز تقيّم الشكاير اختار «شكاير بس» من فلتر التعبئة.</div><?php endif; ?>
</div>
<?php elseif ($showStats && $md !== 'mixed'): ?>
<div class="card pk-quality no-print text-center text-muted py-3">مفيش إنتاج نظامي في الفترة دي عشان نحسب متوسطه.</div>
<?php endif; ?>

<?php if ($showStats && $md !== 'std' && (int)$sMix['n']): ?>
<div class="card pk-quality mixed no-print">
  <div class="pk-q-head"><h6 class="mb-0"><i class="bi bi-shuffle"></i> المتعدد — أرقام وصفية بس</h6>
    <span class="small text-muted">عدد الكونات بيتغيّر في كل كرتونة ومفيش وزن مثالي ثابت ليه، فمش بيتقيّم وما بيدخلش في متوسط النظامي</span></div>
  <div class="row text-center g-2 my-2">
    <div class="col-6 col-md-3"><div class="text-muted small">عدد الكراتين</div><div class="fs-3 fw-bold num"><?= (int)$sMix['n'] ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">متوسط الكونات في الكرتونة</div><div class="fs-3 fw-bold num"><?= fmt_qty($sMix['avg_cones'], 1) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">متوسط قائم الكونة</div><div class="fs-3 fw-bold num"><?= fmt_qty($sMix['per_cone'], 2) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted small">متوسط الصافي (كرتونة)</div><div class="fs-3 fw-bold text-success num"><?= fmt_qty($sMix['avg_net'], 2) ?></div></div>
  </div>
</div>
<?php endif; ?>

<div class="card p-0 pk-table">
<table class="table table-sm table-hover mb-0 align-middle" id="pk" data-csrf="<?= csrf_token() ?>">
  <thead><tr><th>الباركود</th><th>بالتة</th><th>النمرة</th><th>التعبئة</th><th>كونات</th><th>قائم</th><th class="no-print">الفرق</th><th>فوارغ</th><th>صافي</th><th>المصدر</th><th>الوقت</th><th>بواسطة</th><th>الحالة</th><th class="no-print"></th></tr></thead>
  <?php foreach ($rows as $r): $l = $rowLvl($r); $d = $r['gross'] - $r['cones_count'] * $perCone; ?>
    <tr class="<?= $r['status'] === 'void' ? 'text-decoration-line-through text-muted' : '' ?><?= $r['is_mixed'] ? ' pk-mixed' : '' ?>">
      <td class="num fw-bold"><?= e($r['serial']) ?></td>
      <td class="num small"><?= $r['pallet_serial'] ? e($r['pallet_serial']) . ' <span class="pal-no">' . (int)$r['pallet_seq'] . '/' . (int)$r['pallet_cap'] . '</span>' : '—' ?></td>
      <td><b><?= e($r['code']) ?></b> <span class="small text-muted d-none d-xl-inline"><?= e($r['name']) ?></span></td>
      <td class="text-nowrap"><?= $r['is_sack'] ? '<span class="badge bg-success">شكارة</span>' : '<span class="badge bg-light text-dark border">كرتونة</span>' ?><?= $r['is_mixed'] ? ' <span class="badge bg-warning text-dark">متعدد</span>' : '' ?></td>
      <td><?= (int)$r['cones_count'] ?> <span class="small text-muted"><?= e($r['cone']) ?></span></td>
      <td class="num"><?= fmt_qty($r['gross'], 2) ?></td>
      <td class="num no-print"><?php if ($l): ?><span class="dev <?= $l ?>"><?= ($d >= 0 ? '+' : '') . number_format($d, 2) ?></span><?php endif; ?></td>
      <td class="num text-muted"><?= fmt_qty($r['tare'], 3) ?></td><td class="num fw-bold"><?= fmt_qty($r['net'], 3) ?></td>
      <td class="small"><?= $r['weight_source'] === 'scale' ? '<i class="bi bi-speedometer" title="من الميزان"></i> ميزان' : '<i class="bi bi-pencil" title="وزن يدوي"></i> يدوي' ?></td>
      <td class="small text-nowrap"><?= e(substr($r['created_at'], 0, 16)) ?></td><td class="small"><?= e($r['usr']) ?></td>
      <td><?php $stCls = ['in_stock' => 'primary', 'sold' => 'dark', 'void' => 'danger'][$r['status']] ?? 'secondary'; ?><span class="badge bg-<?= $stCls ?>"><?= PKG_STATUS[$r['status']] ?></span><?= $r['void_reason'] ? ' <span class="small">— ' . e($r['void_reason']) . '</span>' : '' ?></td>
      <td class="no-print text-nowrap"><a class="btn btn-sm btn-outline-secondary" target="_blank" href="label.php?id=<?= $r['id'] ?>" title="طباعة الستيكر"><i class="bi bi-printer"></i></a>
        <?php if ($canVoid && $r['status'] === 'in_stock'): ?><button class="btn btn-sm btn-outline-danger void" data-id="<?= $r['id'] ?>" data-serial="<?= e($r['serial']) ?>" title="إلغاء"><i class="bi bi-x-lg"></i></button><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="14" class="text-muted text-center py-4"><i class="bi bi-inbox fs-3 d-block"></i>مفيش كراتين بالفلاتر دي</td></tr><?php endif; ?>
</table>
<?php if (count($rows) >= 1000): ?><div class="p-2 small text-muted text-center">اتعرض أول 1000 كرتونة بس — ضيّق الفلتر لو عايز الباقي (الإجماليات والمتوسطات فوق بتحسب كل الكراتين).</div><?php endif; ?>
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
