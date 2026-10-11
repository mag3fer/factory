<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
require_role('store');

$p = q('SELECT * FROM parties WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$p) { http_response_code(404); exit('غير موجود'); }
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-01-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$opening = party_balance((int)$p['id'], date('Y-m-d', strtotime("$from -1 day")));
$lines = party_ledger((int)$p['id'], $from, $to);
$pageTitle = 'كشف حساب: ' . $p['name'];
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <h4><i class="bi bi-journal-text"></i> كشف حساب — <?= e($p['name']) ?></h4>
  <div class="d-flex gap-2 no-print">
    <a class="btn btn-success btn-sm" href="payments.php?party=<?= $p['id'] ?>&type=<?= $p['type'] === 'customer' ? 'receipt' : 'payment' ?>"><i class="bi bi-cash"></i> سند <?= $p['type'] === 'customer' ? 'قبض' : 'دفع' ?></a>
    <?php if ($p['type'] === 'customer'): ?><a class="btn btn-primary btn-sm" href="sale_new.php"><i class="bi bi-upc-scan"></i> فاتورة بيع</a><?php endif; ?>
    <button onclick="print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> طباعة</button>
  </div>
</div>
<form class="card p-3 mb-3 d-flex flex-row flex-wrap gap-2 align-items-end no-print">
  <input type="hidden" name="id" value="<?= $p['id'] ?>">
  <div><label class="form-label small mb-0">من</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
  <div><label class="form-label small mb-0">إلى</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  <button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
</form>
<div class="card p-3 reveal">
  <div class="d-none d-print-block mb-2"><b><?= e(setting('company_name')) ?></b> — كشف حساب <?= e($p['name']) ?> من <?= e($from) ?> إلى <?= e($to) ?></div>
  <table class="table table-sm table-bordered">
    <thead><tr><th>التاريخ</th><th>المستند</th><th>رقم</th><th>البيان</th><th>مدين (عليه)</th><th>دائن (له)</th><th>الرصيد</th></tr></thead>
    <tr class="table-light"><td><?= e($from) ?></td><td colspan="5">رصيد أول المدة</td><td class="num fw-bold"><?= fmt_qty($opening, 2) ?></td></tr>
    <?php $bal = $opening; $td = $tc = 0; foreach ($lines as $l): $bal += $l['debit'] - $l['credit']; $td += $l['debit']; $tc += $l['credit']; ?>
      <tr><td><?= e($l['date']) ?></td><td><a href="<?= $l['link'] ?>"><?= e($l['kind']) ?></a></td><td><?= (int)$l['ref'] ?></td><td class="small"><?= e($l['desc']) ?></td>
        <td class="num"><?= $l['debit'] ? fmt_qty($l['debit'], 2) : '' ?></td><td class="num"><?= $l['credit'] ? fmt_qty($l['credit'], 2) : '' ?></td>
        <td class="num fw-bold <?= $bal > 0 ? 'text-danger' : 'text-success' ?>"><?= fmt_qty($bal, 2) ?></td></tr>
    <?php endforeach; ?>
    <tr class="table-light fw-bold"><td colspan="4">الإجمالي</td><td class="num"><?= fmt_qty($td, 2) ?></td><td class="num"><?= fmt_qty($tc, 2) ?></td>
      <td class="num <?= $bal > 0 ? 'text-danger' : 'text-success' ?>"><?= fmt_qty(abs($bal), 2) ?> <?= $bal > 0 ? 'عليه' : ($bal < 0 ? 'له' : '') ?></td></tr>
  </table>
  <?php if ((float)$p['opening_balance'] != 0): ?><div class="small text-muted">يشمل رصيد افتتاحي <?= fmt_qty($p['opening_balance'], 2) ?><?= $p['opening_date'] ? ' بتاريخ ' . e($p['opening_date']) : '' ?>.</div><?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php';
