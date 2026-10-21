<?php
// One pallet: its cartons slot by slot, what is still in stock, what was sold and on which invoice.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
$u = require_role('store', 'scale');

$pl = q('SELECT pl.*, f.code, f.name, pt.name ptname FROM pallets pl JOIN items f ON f.id=pl.finished_item_id LEFT JOIN packing_types pt ON pt.id=pl.packing_type_id WHERE pl.id=?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$pl) { http_response_code(404); exit('البالتة غير موجودة'); }

$pk = q("SELECT p.*, c.name cone FROM packages p LEFT JOIN items c ON c.id=p.cone_item_id
         WHERE p.pallet_id=? AND p.status<>'void' ORDER BY p.pallet_seq", [$pl['id']])->fetchAll();
$bySeq = [];
foreach ($pk as $p) $bySeq[(int)$p['pallet_seq']] = $p;

$st = ['n' => count($pk), 'stock' => 0, 'sold' => 0, 'net_stock' => 0.0, 'net_sold' => 0.0, 'cones_stock' => 0, 'net' => 0.0];
$sales = [];
foreach ($pk as $p) {
    $st['net'] += (float)$p['net'];
    if ($p['status'] === 'in_stock') { $st['stock']++; $st['net_stock'] += (float)$p['net']; $st['cones_stock'] += (int)$p['cones_count']; }
    if ($p['status'] === 'sold') {
        $st['sold']++; $st['net_sold'] += (float)$p['net'];
        $s = package_sale((int)$p['id']);
        if ($s) {
            $s['customer'] = q('SELECT name FROM parties WHERE id=?', [$s['customer_id']])->fetchColumn();
            $sales[(int)$p['id']] = $s;
        }
    }
}
$cap = (int)$pl['capacity'];
$pageTitle = 'بالتة ' . $pl['serial'];
if ($st['n'] === 0) $saleBadge = '<span class="badge bg-secondary fs-6">فاضية</span>';
elseif ($st['stock'] === 0) $saleBadge = '<span class="badge bg-dark fs-6">اتباعت بالكامل</span>';
elseif ($st['sold'] > 0) $saleBadge = '<span class="badge bg-warning text-dark fs-6">اتباع منها جزء</span>';
else $saleBadge = '<span class="badge bg-primary fs-6">كاملة في المخزن</span>';

// invoices this pallet appears on
$inv = [];
foreach ($sales as $s) $inv[$s['id']] = $s;
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0">بالتة <span class="num"><?= e($pl['serial']) ?></span> — <?= e($pl['code']) ?> <span class="small text-muted"><?= e($pl['name']) ?></span> <?= $pl['is_mixed'] ? '<span class="badge bg-warning text-dark fs-6">متعدد</span>' : '<span class="badge bg-primary fs-6">نظامي</span>' ?> <span class="badge bg-secondary fs-6"><?= e($pl['ptname'] ?: 'كرتون') ?> — <?= (int)$pl['capacity'] ?></span> <?= $saleBadge ?><?= !$pl['full'] && $pl['closed'] ? ' <span class="badge bg-secondary fs-6">اتقفلت ناقصة</span>' : '' ?></h4>
  <div class="no-print"><a href="pallets.php" class="btn btn-sm btn-outline-secondary">← كل البالتات</a> <button class="btn btn-sm btn-outline-secondary" onclick="print()">طباعة</button></div>
</div>
<div class="row g-2 mb-3 text-center">
  <?php foreach ([['كراتين', $st['n'] . ' / ' . $cap, ''], ['اتباع', $st['sold'], ''], ['متبقي في المخزن', $st['stock'], 'text-primary'],
                  ['كونات متبقية', $st['cones_stock'], ''], ['صافي متبقي (كيلو)', fmt_qty($st['net_stock'], 2), 'text-success'], ['صافي مباع (كيلو)', fmt_qty($st['net_sold'], 2), 'text-muted'],
                  ['إجمالي الصافي (كيلو)', fmt_qty($st['net'], 2), '']] as [$l, $v, $c]): ?>
    <div class="col"><div class="card p-2"><div class="text-muted small"><?= $l ?></div><div class="fs-4 fw-bold num <?= $c ?>"><?= $v ?></div></div></div>
  <?php endforeach; ?>
</div>
<?php if ($inv): ?>
<div class="card p-3 mb-3">
  <h6>اتباعت على الفواتير دي</h6>
  <?php foreach ($inv as $s):
      $n = count(array_filter($sales, fn($x) => (int)$x['id'] === (int)$s['id'])); ?>
    <div><a href="sale_view.php?id=<?= (int)$s['id'] ?>">فاتورة رقم <?= (int)$s['number'] ?></a> — <?= e($s['customer']) ?> — <?= e($s['inv_date']) ?> — <b><?= $n ?></b> كرتونة من البالتة دي</div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="card p-0">
<table class="table table-sm table-hover mb-0 align-middle">
  <thead class="table-light"><tr><th>رقمها</th><th>الباركود</th><th>الحالة</th><th>كونات</th><th>قائم</th><th>صافي</th><th>اتسجلت</th><th>الفاتورة / العميل</th><th class="no-print"></th></tr></thead>
  <?php for ($i = 1; $i <= $cap; $i++): $p = $bySeq[$i] ?? null; ?>
    <?php if (!$p): ?>
      <tr class="text-muted"><td class="num fw-bold"><?= $i ?></td><td colspan="8">— مكان فاضي (لسه ما اتسجلتش كرتونة)</td></tr>
    <?php else: $s = $sales[(int)$p['id']] ?? null; ?>
      <tr class="<?= $p['status'] === 'sold' ? 'text-muted' : '' ?>">
        <td class="num fw-bold"><?= $i ?></td><td class="num"><?= e($p['serial']) ?></td>
        <td><?= $p['status'] === 'sold' ? '<span class="badge bg-dark">مباعة</span>' : '<span class="badge bg-primary">في المخزن</span>' ?></td>
        <td><?= (int)$p['cones_count'] ?> <span class="small text-muted"><?= e($p['cone']) ?></span></td>
        <td class="num"><?= fmt_qty($p['gross'], 2) ?></td><td class="num fw-bold"><?= fmt_qty($p['net'], 2) ?></td>
        <td class="small"><?= e(substr($p['created_at'], 0, 16)) ?></td>
        <td class="small"><?= $s ? '<a href="sale_view.php?id=' . (int)$s['id'] . '">#' . (int)$s['number'] . '</a> — ' . e($s['customer']) . ' — ' . e($s['inv_date']) : ($p['status'] === 'sold' ? '—' : '') ?></td>
        <td class="no-print"><a class="btn btn-sm btn-outline-secondary" target="_blank" href="label.php?id=<?= (int)$p['id'] ?>">الستيكر</a></td>
      </tr>
    <?php endif; ?>
  <?php endfor; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
