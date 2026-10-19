<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('store', 'scale');

$state = in_array($_GET['state'] ?? '', ['open', 'full', 'available'], true) ? $_GET['state'] : '';
$where = ['1=1'];
if ($state === 'open') $where[] = 'pl.full=0';
if ($state === 'full') $where[] = 'pl.full=1';
if ($state === 'available') $where[] = 'x.in_stock > 0';
$rows = q("SELECT pl.*, f.code, f.name,
                  COALESCE(x.cnt,0) cnt, COALESCE(x.in_stock,0) in_stock, COALESCE(x.net,0) net, COALESCE(x.cones,0) cones
           FROM pallets pl JOIN items f ON f.id=pl.finished_item_id
           LEFT JOIN (SELECT pallet_id, COUNT(*) cnt, SUM(status='in_stock') in_stock, SUM(net) net, SUM(cones_count) cones
                      FROM packages WHERE status<>'void' AND pallet_id IS NOT NULL GROUP BY pallet_id) x ON x.pallet_id=pl.id
           WHERE " . implode(' AND ', $where) . ' ORDER BY pl.id DESC LIMIT 300')->fetchAll();

$pageTitle = 'البالتات';
require __DIR__ . '/inc/header.php';
?>
<h4>البالتات (كل <?= (int)setting('pallet_size', '20') ?> كرتونة من نفس النمرة)</h4>
<form class="row g-2 mb-2 no-print">
  <div class="col-auto"><select name="state" class="form-select" onchange="this.form.submit()">
    <option value="">كل البالتات</option>
    <option value="open"<?= $state === 'open' ? ' selected' : '' ?>>مفتوحة (بتتملي)</option>
    <option value="full"<?= $state === 'full' ? ' selected' : '' ?>>مكتملة</option>
    <option value="available"<?= $state === 'available' ? ' selected' : '' ?>>فيها كراتين في المخزن</option>
  </select></div>
</form>
<div class="card p-0">
<table class="table table-sm table-hover mb-0">
  <thead class="table-light"><tr><th>رقم البالتة</th><th>النمرة</th><th>الحالة</th><th>كراتين</th><th>في المخزن</th><th>كونات</th><th>صافي (كيلو)</th><th>أنشئت</th><th class="no-print"></th></tr></thead>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="num fw-bold"><?= e($r['serial']) ?></td><td><?= e($r['code']) ?> <span class="small text-muted"><?= e($r['name']) ?></span></td>
      <td><?= $r['full'] ? '<span class="badge bg-success">مكتملة</span>' : '<span class="badge bg-warning text-dark">بتتملي</span>' ?></td>
      <td><?= (int)$r['cnt'] ?> / <?= (int)$r['capacity'] ?></td><td><?= (int)$r['in_stock'] ?></td><td><?= (int)$r['cones'] ?></td>
      <td class="num fw-bold"><?= fmt_qty($r['net'], 2) ?></td><td><?= e(substr($r['created_at'], 0, 16)) ?></td>
      <td class="no-print"><a class="btn btn-sm btn-outline-secondary" href="packages.php?q=&from=<?= e(substr($r['created_at'], 0, 10)) ?>&to=<?= e(date('Y-m-d')) ?>&item=<?= (int)$r['finished_item_id'] ?>">الكراتين</a></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-muted">مفيش بالتات لسه</td></tr><?php endif; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
