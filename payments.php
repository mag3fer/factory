<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
$u = require_role('store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'void') {
        if ($u['role'] !== 'admin') { flash('الإلغاء للمدير بس', 'danger'); redirect('payments.php'); }
        q('UPDATE payments SET voided=1 WHERE id=?', [(int)$_POST['id']]);
        flash('تم إلغاء السند', 'warning');
        redirect('payments.php');
    }
    try {
        $date = $_POST['pay_date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('تاريخ غير صحيح');
        db()->beginTransaction();
        $id = insert_payment([
            'type' => $_POST['type'] ?? 'receipt', 'pay_date' => $date, 'party_id' => (int)($_POST['party_id'] ?? 0), 'amount' => $_POST['amount'] ?? 0,
            'method' => $_POST['method'] ?? 'cash', 'ref_no' => trim($_POST['ref_no'] ?? ''), 'notes' => trim($_POST['notes'] ?? ''),
        ], $u['id']);
        db()->commit();
        flash('تم حفظ السند');
        redirect('payments.php?id=' . $id);
    } catch (InvalidArgumentException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash($ex->getMessage(), 'danger');
        redirect('payments.php');
    }
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$hl = (int)($_GET['id'] ?? 0);
$rows = q('SELECT y.*, p.name party, p.type ptype, s.number inv_no, s.type inv_type FROM payments y JOIN parties p ON p.id=y.party_id LEFT JOIN sales_invoices s ON s.id=y.invoice_id
           WHERE (y.pay_date BETWEEN ? AND ?) OR y.id=? ORDER BY y.pay_date DESC, y.id DESC', [$from, $to, $hl])->fetchAll();
$parties = q("SELECT id, name, type FROM parties WHERE active=1 ORDER BY type, name")->fetchAll();
$preParty = (int)($_GET['party'] ?? 0);
$preType = ($_GET['type'] ?? '') === 'payment' ? 'payment' : 'receipt';
$in = $out = 0;
foreach ($rows as $r) if (!$r['voided']) { if ($r['type'] === 'receipt') $in += $r['amount']; else $out += $r['amount']; }
$pageTitle = 'التحصيلات والمدفوعات';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head"><h4><i class="bi bi-cash-coin"></i> التحصيلات والمدفوعات</h4></div>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="card p-3 reveal">
      <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle"></i> سند جديد</h6>
      <form method="post">
        <?= csrf_field() ?>
        <div class="btn-group w-100 mb-3" role="group">
          <input type="radio" class="btn-check" name="type" id="tR" value="receipt"<?= $preType === 'receipt' ? ' checked' : '' ?>><label class="btn btn-outline-success" for="tR"><i class="bi bi-box-arrow-in-down"></i> سند قبض (استلمنا)</label>
          <input type="radio" class="btn-check" name="type" id="tP" value="payment"<?= $preType === 'payment' ? ' checked' : '' ?>><label class="btn btn-outline-danger" for="tP"><i class="bi bi-box-arrow-up"></i> سند دفع (دفعنا)</label>
        </div>
        <div class="mb-2"><label class="form-label small">العميل / المورد</label><select name="party_id" class="form-select" required><option value="">— اختر —</option>
          <optgroup label="العملاء"><?php foreach ($parties as $p) if ($p['type'] === 'customer'): ?><option value="<?= $p['id'] ?>"<?= $preParty === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endif; ?></optgroup>
          <optgroup label="الموردين"><?php foreach ($parties as $p) if ($p['type'] === 'supplier'): ?><option value="<?= $p['id'] ?>"<?= $preParty === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endif; ?></optgroup></select></div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small">المبلغ</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control num fs-5" required></div>
          <div class="col-6"><label class="form-label small">التاريخ</label><input name="pay_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-6"><label class="form-label small">الطريقة</label><select name="method" class="form-select"><?php foreach (PAY_METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label small">رقم الشيك / التحويل</label><input name="ref_no" class="form-control"></div>
        </div>
        <div class="mb-3"><input name="notes" class="form-control" placeholder="ملاحظات"></div>
        <button class="btn btn-success w-100"><i class="bi bi-check2"></i> حفظ السند</button>
      </form>
    </div>
  </div>
  <div class="col-xl-8">
    <form class="d-flex flex-wrap gap-2 mb-2 no-print align-items-end">
      <div><label class="form-label small mb-0">من</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
      <div><label class="form-label small mb-0">إلى</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
      <button class="btn btn-primary"><i class="bi bi-search"></i></button>
      <div class="ms-auto d-flex gap-3"><span class="badge-soft ok fs-6">قبض: <span class="num"><?= fmt_qty($in, 2) ?></span></span><span class="badge-soft bad fs-6">دفع: <span class="num"><?= fmt_qty($out, 2) ?></span></span></div>
    </form>
    <div class="card table-wrap reveal">
    <table class="table table-hover">
      <thead><tr><th>النوع</th><th>رقم</th><th>التاريخ</th><th>الاسم</th><th>المبلغ</th><th>الطريقة</th><th>بيان</th><th></th></tr></thead>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['voided'] ? 'text-decoration-line-through text-muted' : '' ?><?= $hl === (int)$r['id'] ? ' table-info' : '' ?>">
          <td><span class="badge-soft <?= $r['type'] === 'receipt' ? 'ok' : 'bad' ?>"><?= PAY_TYPES[$r['type']] ?></span></td><td><?= (int)$r['number'] ?></td><td><?= e($r['pay_date']) ?></td>
          <td><a href="party_statement.php?id=<?= $r['party_id'] ?>"><?= e($r['party']) ?></a></td><td class="num fw-bold"><?= fmt_qty($r['amount'], 2) ?></td>
          <td><?= PAY_METHODS[$r['method']] ?><?= $r['ref_no'] ? ' <span class="small text-muted">' . e($r['ref_no']) . '</span>' : '' ?></td>
          <td class="small"><?= $r['inv_no'] ? '<a href="sale_view.php?id=' . $r['invoice_id'] . '">' . SALE_TYPES[$r['inv_type']] . ' ' . (int)$r['inv_no'] . '</a> ' : '' ?><?= e($r['notes']) ?></td>
          <td class="no-print"><?php if (!$r['voided'] && $u['role'] === 'admin' && !$r['invoice_id']): ?>
            <form method="post" onsubmit="return confirm('إلغاء السند؟')"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></form><?php endif; ?></td></tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">مفيش سندات في الفترة دي</td></tr><?php endif; ?>
    </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
