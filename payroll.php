<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hr.php';
$u = require_role('admin');

$ok = fn(string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST[$k] ?? '') ? $_REQUEST[$k] : $def;
$from = $ok('from', date('Y-m-01'));
$to = $ok('to', date('Y-m-t'));
if ($from > $to) [$from, $to] = [$to, $from];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $r = post_payroll($from, $to, $u['id']);
        $msg = 'تم اعتماد مرتبات ' . $r['posted'] . ' عامل عن الفترة';
        if ($r['skipped']) $msg .= ' — اتخطّى (معتمدين قبل كده): ' . implode('، ', $r['skipped']);
        flash($msg, $r['posted'] ? 'success' : 'warning');
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'danger');
    }
    redirect("payroll.php?from=$from&to=$to");
}

$rows = payroll_rows($from, $to);
$wageRows = array_filter($rows, fn($r) => $r['pay_type'] !== 'trip');
$pending = count(array_filter($wageRows, fn($r) => !$r['posted'] && $r['wage'] > 0));
$totWage = array_sum(array_map(fn($r) => $r['posted'] ? (float)$r['posted']['amt'] : $r['wage'], $wageRows));
$totBal = array_sum(array_map(fn($r) => max(0, $r['balance']), $rows));
$pageTitle = 'المرتبات';
require __DIR__ . '/inc/header.php';
?>
<style>@media print { .card { box-shadow: none; } body { color: #000; } table th, table td { color: #000 !important; } }</style>
<div class="page-head no-print"><h4><i class="bi bi-wallet2"></i> المرتبات</h4></div>
<form class="card p-3 mb-3 no-print" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
    <div class="col-md-3"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
    <div class="col-md-6 d-flex gap-2 flex-wrap">
      <button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
      <a class="btn btn-outline-secondary" href="payroll.php?from=<?= date('Y-m-01', strtotime('first day of last month')) ?>&to=<?= date('Y-m-t', strtotime('first day of last month')) ?>">الشهر اللي فات</a>
      <a class="btn btn-outline-secondary" href="payroll.php">الشهر ده</a>
      <button type="button" onclick="print()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> طباعة الكشف</button>
    </div>
  </div>
  <div class="form-text mt-2">اليومية = الأيام × اليومية. الشهري = المرتب كامل للشهر (بنسبة الأيام لو الفترة جزء من شهر). الغياب والراحة بيتحسبوا من «الراحات المسموحة» للعامل، والخصم (المرتب ÷ 30 لليوم) بيبدأ بعد ما يعدّيها بس. السلف المسجّلة بتتخصم لوحدها لأنها بتنقص رصيد العامل.</div>
</form>
<div class="card p-0 table-wrap">
  <div class="p-3 pb-2 card-title-row"><h6>كشف مرتبات من <?= e($from) ?> إلى <?= e($to) ?></h6>
    <?php if ($pending): ?><form method="post" class="no-print" onsubmit="return confirm('اعتماد مرتبات <?= $pending ?> عامل للفترة دي؟ هتتسجل في حساباتهم.')"><?= csrf_field() ?><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
      <button class="btn btn-success"><i class="bi bi-check2-all"></i> اعتماد مرتبات الفترة (<?= $pending ?>)</button></form><?php endif; ?></div>
  <table class="table table-sm align-middle mb-0">
    <thead><tr><th>العامل</th><th>الوظيفة</th><th>الحساب</th><th>أيام / غياب</th><th>إضافي</th><th>المرتب المحسوب</th><th>رصيده الحالي (له / عليه)</th><th>الحالة</th><th class="no-print"></th></tr></thead>
    <?php foreach ($rows as $r): $isTrip = $r['pay_type'] === 'trip'; ?>
      <tr><td class="fw-bold"><a href="hr_statement.php?id=<?= $r['id'] ?>"><?= e($r['name']) ?></a></td><td class="small"><?= e($r['job'] ?: WORKER_ROLES[$r['role']]) ?></td><td class="small"><?= e(PAY_BASIS[$r['pay_type']]) ?><?= $isTrip ? '' : ' · ' . money((float)$r['rate']) ?></td>
        <td class="small"><?php if ($isTrip): ?>—<?php elseif ($r['pay_type'] === 'daily'): ?><?= fmt_qty($r['days'], 1) ?> يوم<?= $r['absent'] || $r['rest'] ? ' <span class="text-muted">(' . ($r['absent'] + $r['rest']) . ' غياب/راحة)</span>' : '' ?>
          <?php else: ?><?= $r['off'] > 0 ? 'غياب/راحة <b>' . fmt_qty($r['off'], 1) . '</b> من <b>' . (int)$r['allowed'] . '</b> راحة مسموحة' . ($r['excess'] > 0 ? ' <span class="badge-soft bad">زيادة ' . fmt_qty($r['excess'], 1) . ' يوم</span>' : ' <span class="badge-soft ok">من غير خصم</span>') : 'كامل' ?><?= !$r['started'] ? ' · لسه ما بدأش' : ($r['eff_from'] > $from ? ' · بدأ ' . $r['eff_from'] : '') ?><?php endif; ?></td>
        <td class="small"><?= $r['ot'] > 0 ? fmt_qty($r['ot'], 1) . ' س' . ((float)$r['ot_rate'] > 0 ? '' : ' <span class="text-muted">(من غير سعر)</span>') : '' ?></td>
        <td class="num fw-bold"><?= $isTrip ? '<span class="text-muted">بالمشاوير</span>' : money($r['posted'] ? (float)$r['posted']['amt'] : $r['wage']) ?></td>
        <td class="num fw-bold <?= $r['balance'] < -0.004 ? 'neg' : '' ?>"><?= money(abs($r['balance'])) ?> <small class="text-muted"><?= $r['balance'] > 0.004 ? 'له' : ($r['balance'] < -0.004 ? 'عليه' : '') ?></small></td>
        <td><?= $isTrip ? '' : ($r['posted'] ? '<span class="badge-soft ok">معتمد</span>' : ($r['wage'] > 0 ? '<span class="badge-soft warn">لسه</span>' : '')) ?></td>
        <td class="no-print text-nowrap"><?php if ($r['balance'] > 0.004): ?><a class="btn btn-sm btn-success" href="hr_statement.php?id=<?= $r['id'] ?>&pay=1#pay">صرف <?= money($r['balance']) ?></a><?php endif; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-3">مفيش عاملين</td></tr><?php endif; ?>
    <tfoot class="table-light"><tr><th colspan="5">إجمالي مرتبات الفترة</th><th class="num"><?= money($totWage) ?></th><th class="num"><?= money($totBal) ?> <small class="text-muted">مستحق للعاملين</small></th><th colspan="2"></th></tr></tfoot>
  </table>
</div>
<?php require __DIR__ . '/inc/footer.php';
