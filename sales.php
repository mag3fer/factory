<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
require_role('store');

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$cust = (int)($_GET['customer'] ?? 0);
$type = isset(SALE_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';

$where = ['s.inv_date BETWEEN ? AND ?']; $params = [$from, $to];
if ($cust) { $where[] = 's.customer_id=?'; $params[] = $cust; }
if ($type) { $where[] = 's.type=?'; $params[] = $type; }
$rows = q('SELECT s.*, p.name customer, (SELECT COALESCE(SUM(qty),0) FROM sales_lines WHERE invoice_id=s.id) kg,
                  (SELECT COUNT(*) FROM sales_packages WHERE invoice_id=s.id) cartons,
                  (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=s.id AND voided=0) paid
           FROM sales_invoices s JOIN parties p ON p.id=s.customer_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.inv_date DESC, s.id DESC', $params)->fetchAll();
$customers = q("SELECT id, name FROM parties WHERE type='customer' ORDER BY name")->fetchAll();
$tot = ['kg' => 0, 'amount' => 0];
foreach ($rows as $r) if (!$r['voided']) { $s = $r['type'] === 'sale' ? 1 : -1; $tot['kg'] += $s * $r['kg']; $tot['amount'] += $s * $r['total']; }
$pageTitle = 'فواتير البيع';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <h4><i class="bi bi-receipt"></i> فواتير البيع والمرتجعات</h4>
  <div class="d-flex gap-2"><a href="sale_new.php" class="btn btn-success"><i class="bi bi-upc-scan"></i> فاتورة بيع</a><a href="sale_new.php?type=return" class="btn btn-outline-warning"><i class="bi bi-arrow-return-right"></i> مرتجع</a></div>
</div>
<form class="card p-3 mb-3 row g-2 mx-0 no-print reveal">
  <div class="col-md-2"><label class="form-label small">من</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><label class="form-label small">إلى</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  <div class="col-md-3"><label class="form-label small">العميل</label><select name="customer" class="form-select"><option value="">الكل</option>
    <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $cust === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small">النوع</label><select name="type" class="form-select"><option value="">الكل</option>
    <?php foreach (SALE_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 d-flex align-items-end gap-2"><button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button><button type="button" onclick="print()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i></button></div>
</form>
<div class="row g-3 mb-3">
  <div class="col-md-4 reveal"><div class="card kpi"><div class="ic g-blue"><i class="bi bi-receipt"></i></div><div><div class="lbl">عدد الفواتير</div><div class="value"><span data-count="<?= count($rows) ?>">0</span></div></div></div></div>
  <div class="col-md-4 reveal"><div class="card kpi"><div class="ic g-cyan"><i class="bi bi-box-seam"></i></div><div><div class="lbl">صافي الكمية (كيلو)</div><div class="value num"><span data-count="<?= round($tot['kg'], 1) ?>" data-dec="1">0</span></div></div></div></div>
  <div class="col-md-4 reveal"><div class="card kpi"><div class="ic g-green"><i class="bi bi-cash-stack"></i></div><div><div class="lbl">صافي المبيعات</div><div class="value num"><span data-count="<?= round($tot['amount'], 2) ?>" data-dec="2">0</span></div></div></div></div>
</div>
<div class="card table-wrap reveal">
<table class="table table-hover">
  <thead><tr><th>النوع</th><th>رقم</th><th>التاريخ</th><th>العميل</th><th>كراتين</th><th>كيلو</th><th>الصافي</th><th>المدفوع معاها</th><th></th></tr></thead>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['voided'] ? 'text-decoration-line-through text-muted' : '' ?>">
      <td><span class="badge-soft <?= $r['type'] === 'sale' ? 'ok' : 'warn' ?>"><?= SALE_TYPES[$r['type']] ?></span></td><td class="fw-bold"><?= (int)$r['number'] ?></td><td><?= e($r['inv_date']) ?></td>
      <td><?= e($r['customer']) ?></td><td><?= (int)$r['cartons'] ?: '' ?></td><td class="num"><?= fmt_qty($r['kg'], 1) ?></td><td class="num fw-bold"><?= fmt_qty($r['total'], 2) ?></td>
      <td class="num"><?= $r['paid'] > 0 ? fmt_qty($r['paid'], 2) : '' ?></td>
      <td class="no-print"><a class="btn btn-sm btn-outline-primary" href="sale_view.php?id=<?= $r['id'] ?>"><i class="bi bi-eye"></i></a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block"></i>مفيش فواتير في الفترة دي</td></tr><?php endif; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
