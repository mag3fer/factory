<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hr.php';
$u = require_role('admin');

const ENTRY_TYPES = ['advance' => 'سلفة (بتتخصم من المرتب)', 'deduction' => 'خصم / جزاء', 'bonus' => 'مكافأة / إضافي'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'void') {
        q("UPDATE hr_ledger SET voided=1 WHERE id=? AND kind IN ('advance','deduction','bonus')", [(int)$_POST['id']]);
        flash('تم إلغاء القيد', 'warning');
        redirect('hr_entries.php');
    }
    try {
        $kind = isset(ENTRY_TYPES[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'advance';
        ledger_add(['worker_id' => (int)($_POST['worker_id'] ?? 0), 'kind' => $kind, 'amount' => $_POST['amount'] ?? 0,
                    'entry_date' => $_POST['entry_date'] ?? '', 'notes' => $_POST['notes'] ?? ''], $u['id']);
        $b = hr_balance((int)$_POST['worker_id']);
        flash('تم التسجيل — رصيد العامل دلوقتي ' . money(abs($b)) . ($b > 0.004 ? ' له' : ($b < -0.004 ? ' عليه (هيتخصم من المرتب)' : '')));
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'danger');
    }
    redirect('hr_entries.php' . (!empty($_POST['worker_id']) ? '?worker=' . (int)$_POST['worker_id'] : ''));
}

$wf = (int)($_GET['worker'] ?? 0);
$workers = q('SELECT id, name, role FROM workers WHERE active=1 ORDER BY name')->fetchAll();
$rows = q("SELECT l.*, w.name FROM hr_ledger l JOIN workers w ON w.id=l.worker_id WHERE l.kind IN ('advance','deduction','bonus') AND (?=0 OR l.worker_id=?) ORDER BY l.entry_date DESC, l.id DESC LIMIT 150", [$wf, $wf])->fetchAll();
$bal = hr_balances();
$pageTitle = 'السلف والخصومات';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head"><h4><i class="bi bi-cash-stack"></i> السلف والخصومات والمكافآت</h4></div>
<div class="row g-3">
  <div class="col-xl-4">
    <form method="post" class="card p-3">
      <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle"></i> قيد جديد</h6>
      <?= csrf_field() ?>
      <div class="mb-2"><label class="form-label small">العامل / السواق</label><select name="worker_id" class="form-select" required><option value="">— اختر —</option>
        <?php foreach ($workers as $w): ?><option value="<?= $w['id'] ?>"<?= $wf === (int)$w['id'] ? ' selected' : '' ?>><?= e($w['name']) ?> — <?= e(WORKER_ROLES[$w['role']]) ?></option><?php endforeach; ?></select></div>
      <div class="mb-2"><label class="form-label small">النوع</label>
        <div class="btn-group w-100"><?php $i = 0; foreach (ENTRY_TYPES as $k => $l): ?><input type="radio" class="btn-check" name="kind" id="k<?= $i ?>" value="<?= $k ?>"<?= $i === 0 ? ' checked' : '' ?>><label class="btn btn-outline-primary btn-sm" for="k<?= $i++ ?>"><?= e($l) ?></label><?php endforeach; ?></div></div>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">المبلغ</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control num fs-5" required></div>
        <div class="col-6"><label class="form-label small">التاريخ</label><input name="entry_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div></div>
      <div class="mb-3"><label class="form-label small">ملاحظات</label><input name="notes" class="form-control" maxlength="255"></div>
      <button class="btn btn-success"><i class="bi bi-check2"></i> حفظ</button>
      <div class="form-text mt-2">السلفة بتتخصم لوحدها من مرتبه: رصيده بيبقى «عليه» لحد ما المرتب يتعتمد.</div>
    </form>
  </div>
  <div class="col-xl-8">
    <div class="card p-0 table-wrap">
      <div class="p-3 pb-2 card-title-row"><h6>آخر القيود <?= $wf ? '— عامل واحد' : '' ?></h6><?php if ($wf): ?><a href="hr_entries.php" class="btn btn-sm btn-outline-secondary">كل العاملين</a><?php endif; ?></div>
      <table class="table table-sm mb-0"><thead><tr><th>التاريخ</th><th>العامل</th><th>النوع</th><th>المبلغ</th><th>الرصيد الحالي</th><th>ملاحظات</th><th></th></tr></thead>
      <?php foreach ($rows as $r): $b = $bal[(int)$r['worker_id']] ?? 0.0; ?>
        <tr class="<?= $r['voided'] ? 'text-decoration-line-through text-muted' : '' ?>"><td><?= e($r['entry_date']) ?></td><td class="fw-bold"><a href="hr_statement.php?id=<?= $r['worker_id'] ?>"><?= e($r['name']) ?></a></td>
          <td><span class="badge-soft <?= $r['kind'] === 'bonus' ? 'ok' : ($r['kind'] === 'advance' ? 'warn' : 'bad') ?>"><?= e(LEDGER_KINDS[$r['kind']]) ?></span></td>
          <td class="num fw-bold"><?= money((float)$r['amount']) ?></td>
          <td class="num <?= $b < -0.004 ? 'neg' : '' ?>"><?= money(abs($b)) ?> <small class="text-muted"><?= $b > 0.004 ? 'له' : ($b < -0.004 ? 'عليه' : '') ?></small></td>
          <td class="small"><?= e($r['notes'] ?? '') ?></td>
          <td><?php if (!$r['voided']): ?><form method="post" onsubmit="return confirm('إلغاء القيد ده؟')"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></form><?php endif; ?></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">مفيش قيود لسه</td></tr><?php endif; ?></table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
