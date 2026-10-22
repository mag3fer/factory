<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hr.php';
$u = require_role('admin');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$w = q('SELECT * FROM workers WHERE id=?', [$id])->fetch();
if (!$w) { http_response_code(404); exit('غير موجود'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'void') {
        if (q("SELECT kind FROM hr_ledger WHERE id=? AND worker_id=?", [(int)$_POST['entry'], $id])->fetchColumn() === 'trip') {
            flash('المشاوير بتتلغي من شاشة «المشاوير» عشان تفضل متطابقة', 'danger');
            redirect("hr_statement.php?id=$id");
        }
        q('UPDATE hr_ledger SET voided=1 WHERE id=? AND worker_id=?', [(int)$_POST['entry'], $id]);
        flash('تم إلغاء القيد', 'warning');
        redirect("hr_statement.php?id=$id");
    }
    if ($act === 'pay') {
        try {
            $eid = ledger_add(['worker_id' => $id, 'kind' => 'payment', 'amount' => $_POST['amount'] ?? 0, 'entry_date' => $_POST['entry_date'] ?? '', 'notes' => $_POST['notes'] ?? ''], $u['id']);
            flash('تم تسجيل الصرف');
            redirect("hr_receipt.php?id=$eid");
        } catch (InvalidArgumentException $ex) {
            flash($ex->getMessage(), 'danger');
            redirect("hr_statement.php?id=$id");
        }
    }
}

$ok = fn(string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
$from = $ok('from', date('Y-01-01'));
$to = $ok('to', date('Y-m-d'));
$opening = (float)(hr_balances(date('Y-m-d', strtotime($from . ' -1 day')))[$id] ?? 0);
$rows = q('SELECT * FROM hr_ledger WHERE worker_id=? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, id', [$id, $from, $to])->fetchAll();
$run = $opening; $dr = $cr = 0.0;   // cr = what the person earned (له), dr = what was taken / paid (عليه)
foreach ($rows as &$r) {
    if (!$r['voided']) { $s = hr_sign($r['kind']); $run += $s * (float)$r['amount']; if ($s > 0) $cr += (float)$r['amount']; else $dr += (float)$r['amount']; }
    $r['run'] = $run;
}
unset($r);
$balance = hr_balance($id);
$acc = accrued_wage($w, date('Y-m-d'));            // earned so far, not posted yet
$dueToday = round($balance + $acc['wage'], 2);     // what he is really owed today (negative = he owes us)
$att = attendance_totals($from, $to)[$id] ?? ['present' => 0, 'half' => 0, 'absent' => 0, 'rest' => 0, 'ot' => 0];
$trips = (int)q("SELECT COUNT(*) FROM trips WHERE driver_id=? AND voided=0 AND trip_date BETWEEN ? AND ?", [$id, $from, $to])->fetchColumn();
$pageTitle = 'كشف حساب ' . $w['name'];
require __DIR__ . '/inc/header.php';
?>
<style>@media print { .card { box-shadow: none; } body { color: #000; } @page { size: A4; margin: 10mm; } }</style>
<div class="page-head no-print"><h4><i class="bi bi-journal-text"></i> كشف حساب: <?= e($w['name']) ?></h4>
  <div class="d-flex gap-2"><a class="btn btn-secondary" href="workers.php"><i class="bi bi-arrow-right"></i> رجوع</a><button onclick="print()" class="btn btn-primary"><i class="bi bi-printer"></i> طباعة</button></div></div>
<form class="card p-3 mb-3 no-print" method="get"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label">من</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
    <div class="col-md-3"><label class="form-label">إلى</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div><div class="col-md-3"><button class="btn btn-primary">عرض</button></div></div></form>
<div class="card p-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div><div class="fs-4 fw-bold"><?= e($w['name']) ?></div><div class="text-muted"><?= e(WORKER_ROLES[$w['role']]) ?><?= $w['job'] ? ' — ' . e($w['job']) : '' ?> · <?= e(PAY_BASIS[$w['pay_type']]) ?><?= $w['pay_type'] === 'trip' ? '' : ' ' . money((float)$w['rate']) ?></div>
      <div class="small text-muted">الفترة: <?= e($from) ?> → <?= e($to) ?> · حضور <?= $att['present'] ?> يوم<?= $att['half'] ? ' + ' . $att['half'] . ' نص يوم' : '' ?><?= $att['absent'] ? ' · غياب ' . $att['absent'] : '' ?><?= $att['ot'] > 0 ? ' · إضافي ' . fmt_qty($att['ot'], 1) . ' س' : '' ?><?= $trips ? ' · ' . $trips . ' مشوار' : '' ?></div></div>
    <div class="text-center border rounded p-2" style="min-width:280px">
      <div class="text-muted small">المستحق له لحد النهارده (<?= e(date('d/m/Y')) ?>)</div>
      <div class="fs-2 fw-bold num <?= $dueToday < -0.004 ? 'neg' : 'text-success' ?>"><?= money(abs($dueToday)) ?></div>
      <div class="fw-bold"><?= $dueToday > 0.004 ? 'مستحق له' : ($dueToday < -0.004 ? 'عليه' : 'متسوّي') ?></div>
      <?php if ($w['pay_type'] !== 'trip'): ?>
      <table class="table table-sm mb-0 mt-2 small text-start"><tbody>
        <tr><td>كسب لسه ما اتعتمدش<?= $acc['from'] ? '<br><span class="text-muted">من ' . e($acc['from']) . ' لحد ' . e($acc['to']) . '</span>' : '' ?></td><td class="num fw-bold text-end">+ <?= money($acc['wage']) ?></td></tr>
        <tr><td>رصيد الحساب المسجّل<br><span class="text-muted"><?= $balance > 0.004 ? 'مستحق' : ($balance < -0.004 ? 'سلف / خصومات / صرف' : '') ?></span></td><td class="num fw-bold text-end <?= $balance < -0.004 ? 'neg' : '' ?>"><?= $balance < -0.004 ? '−' : '' ?> <?= money(abs($balance)) ?></td></tr>
      </tbody></table>
      <?php endif; ?>
    </div>
  </div>
  <table class="table table-sm table-bordered mb-0">
    <thead class="table-light"><tr><th>التاريخ</th><th>البيان</th><th>له (كسب)</th><th>عليه (سلفة / خصم / صرف)</th><th>الرصيد</th><th class="no-print"></th></tr></thead>
    <?php if (abs($opening) > 0.004): ?><tr class="table-light"><td colspan="4">رصيد سابق</td><td class="num"><?= money($opening) ?></td><td class="no-print"></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $s = hr_sign($r['kind']); ?>
      <tr class="<?= $r['voided'] ? 'text-decoration-line-through text-muted' : '' ?>"><td><?= e($r['entry_date']) ?></td>
        <td><b><?= e(LEDGER_KINDS[$r['kind']]) ?></b> <span class="small text-muted"><?= e($r['notes'] ?? '') ?></span><?= $r['kind'] === 'payment' && !$r['voided'] ? ' <a class="no-print small" href="hr_receipt.php?id=' . $r['id'] . '">إيصال</a>' : '' ?></td>
        <td class="num"><?= $s > 0 ? money((float)$r['amount']) : '' ?></td><td class="num"><?= $s < 0 ? money((float)$r['amount']) : '' ?></td>
        <td class="num fw-bold"><?= $r['voided'] ? '' : money($r['run']) ?></td>
        <td class="no-print"><?php if (!$r['voided']): ?><form method="post" onsubmit="return confirm('إلغاء القيد ده؟ هيتشال من الرصيد.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="entry" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></form><?php endif; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">مفيش حركات في الفترة دي</td></tr><?php endif; ?>
    <tfoot class="table-light"><tr><th colspan="2">الإجمالي</th><th class="num"><?= money($cr) ?></th><th class="num"><?= money($dr) ?></th><th class="num"><?= money($run) ?></th><th class="no-print"></th></tr></tfoot>
  </table>
</div>
<form method="post" class="card p-3 mt-3 no-print" id="pay">
  <h6 class="fw-bold"><i class="bi bi-cash-coin"></i> تسجيل صرف لـ<?= e($w['name']) ?></h6>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="pay">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small">المبلغ (المستحق لحد النهارده: <?= money(max(0, $dueToday)) ?>)</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control num fs-5" value="<?= $dueToday > 0.004 ? e((string)round($dueToday, 2)) : '' ?>" <?= isset($_GET['pay']) ? 'autofocus' : '' ?> required></div>
    <div class="col-md-2"><label class="form-label small">التاريخ</label><input name="entry_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-5"><label class="form-label small">ملاحظات</label><input name="notes" class="form-control" maxlength="255" placeholder="مثال: صرف مرتب أكتوبر / دفعة"></div>
    <div class="col-md-2"><button class="btn btn-success w-100">صرف وإيصال</button></div>
  </div>
</form>
<?php require __DIR__ . '/inc/footer.php';
