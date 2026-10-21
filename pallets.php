<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('store', 'scale');

// stock = cartons still in the warehouse; sold = cartons sold on a (non-voided) sale invoice
$view = ['' => 'كل البالتات', 'stock' => 'فيها كراتين في المخزن', 'partial' => 'اتباع منها جزء', 'sold' => 'اتباعت بالكامل', 'open' => 'لسه بتتملي', 'std' => 'نظامي بس', 'mixed' => 'متعدد بس'];
$state = $_GET['state'] ?? '';
if (!array_key_exists($state, $view)) $state = '';
$having = match ($state) {
    'stock' => 'in_stock > 0',
    'partial' => 'in_stock > 0 AND sold > 0',
    'sold' => 'cnt > 0 AND in_stock = 0',
    'open' => 'pl.full = 0 AND pl.closed = 0',
    'std' => 'pl.is_mixed = 0',
    'mixed' => 'pl.is_mixed = 1',
    default => '1=1',
};
$rows = q("SELECT pl.*, f.code, f.name, pt.name ptname,
                  COALESCE(x.cnt,0) cnt, COALESCE(x.in_stock,0) in_stock, COALESCE(x.sold,0) sold,
                  COALESCE(x.net,0) net, COALESCE(x.net_stock,0) net_stock, COALESCE(x.net_sold,0) net_sold,
                  COALESCE(x.cones_stock,0) cones_stock,
                  (SELECT GROUP_CONCAT(DISTINCT pa.name ORDER BY pa.name SEPARATOR '، ')
                     FROM sales_packages sp JOIN sales_invoices s ON s.id=sp.invoice_id AND s.type='sale' AND s.voided=0
                     JOIN parties pa ON pa.id=s.customer_id JOIN packages pk ON pk.id=sp.package_id
                    WHERE pk.pallet_id=pl.id AND pk.status='sold') customers
           FROM pallets pl JOIN items f ON f.id=pl.finished_item_id LEFT JOIN packing_types pt ON pt.id=pl.packing_type_id
           LEFT JOIN (SELECT pallet_id, COUNT(*) cnt, SUM(status='in_stock') in_stock, SUM(status='sold') sold, SUM(net) net,
                             SUM(IF(status='in_stock', net, 0)) net_stock, SUM(IF(status='sold', net, 0)) net_sold,
                             SUM(IF(status='in_stock', cones_count, 0)) cones_stock
                      FROM packages WHERE status<>'void' AND pallet_id IS NOT NULL GROUP BY pallet_id) x ON x.pallet_id=pl.id
           HAVING $having ORDER BY pl.id DESC LIMIT 300")->fetchAll();

$tot = ['pallets' => 0, 'cartons' => 0, 'kg' => 0.0];
foreach ($rows as $r) if ((int)$r['in_stock'] > 0) { $tot['pallets']++; $tot['cartons'] += (int)$r['in_stock']; $tot['kg'] += (float)$r['net_stock']; }

$pageTitle = 'البالتات';
require __DIR__ . '/inc/header.php';
?>
<h4>البالتات <span class="small text-muted">(كرتونة 20 / شكارة 15 من نفس النمرة والنوع — السعة بتتغيّر من «تعديل الأوزان» في الميزان)</span></h4>
<div class="row g-2 mb-2 no-print">
  <div class="col-auto"><div class="card px-3 py-2"><div class="text-muted small">بالتات في المخزن</div><div class="fs-4 fw-bold num"><?= $tot['pallets'] ?></div></div></div>
  <div class="col-auto"><div class="card px-3 py-2"><div class="text-muted small">كراتين متبقية</div><div class="fs-4 fw-bold num"><?= $tot['cartons'] ?></div></div></div>
  <div class="col-auto"><div class="card px-3 py-2"><div class="text-muted small">صافي المتبقي (كيلو)</div><div class="fs-4 fw-bold text-success num"><?= fmt_qty($tot['kg'], 1) ?></div></div></div>
  <div class="col-auto ms-auto align-self-end"><form><select name="state" class="form-select" onchange="this.form.submit()">
    <?php foreach ($view as $k => $l): ?><option value="<?= $k ?>"<?= $state === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select></form></div>
</div>
<div class="card p-0">
<table class="table table-sm table-hover mb-0 align-middle">
  <thead class="table-light"><tr><th>رقم البالتة</th><th>النمرة</th><th>الاكتمال</th><th>البيع</th><th>كراتين</th><th>مباع</th><th>متبقي في المخزن</th><th>صافي متبقي (كيلو)</th><th>اتباعت لـ</th><th>أنشئت</th><th class="no-print"></th></tr></thead>
  <?php foreach ($rows as $r):
      $cnt = (int)$r['cnt']; $stock = (int)$r['in_stock']; $sold = (int)$r['sold'];
      if ($cnt === 0) $sale = '<span class="badge bg-secondary">فاضية</span>';
      elseif ($stock === 0) $sale = '<span class="badge bg-dark">اتباعت بالكامل</span>';
      elseif ($sold > 0) $sale = '<span class="badge bg-warning text-dark">اتباع منها ' . $sold . '</span>';
      else $sale = '<span class="badge bg-primary">كاملة في المخزن</span>'; ?>
    <tr<?= $cnt > 0 && $stock === 0 ? ' class="text-muted"' : '' ?>>
      <td class="num fw-bold"><a href="pallet_view.php?id=<?= $r['id'] ?>"><?= e($r['serial']) ?></a><?= $r['is_mixed'] ? ' <span class="badge bg-warning text-dark">متعدد</span>' : '' ?><?= str_starts_with((string)$r['ptname'], 'شكارة') ? ' <span class="badge bg-success">شكاير</span>' : '' ?></td>
      <td><?= e($r['code']) ?> <span class="small text-muted"><?= e($r['name']) ?></span></td>
      <td><?= $r['full'] ? '<span class="badge bg-success">مكتملة</span>' : ($r['closed'] ? '<span class="badge bg-secondary">اتقفلت ناقصة</span>' : '<span class="badge bg-info text-dark">بتتملي</span>') ?></td>
      <td><?= $sale ?></td>
      <td><?= $cnt ?> / <?= (int)$r['capacity'] ?></td>
      <td><?= $sold ?></td>
      <td class="fw-bold"><?= $stock ?><?= $stock ? ' <span class="small text-muted">(' . (int)$r['cones_stock'] . ' كونة)</span>' : '' ?></td>
      <td class="num fw-bold"><?= fmt_qty($r['net_stock'], 2) ?></td>
      <td class="small"><?= e($r['customers'] ?? '') ?: '—' ?></td>
      <td class="small"><?= e(substr($r['created_at'], 0, 16)) ?></td>
      <td class="no-print"><a class="btn btn-sm btn-outline-primary" href="pallet_view.php?id=<?= $r['id'] ?>">التفاصيل</a></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="11" class="text-muted">مفيش بالتات</td></tr><?php endif; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
