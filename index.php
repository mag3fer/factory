<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role();
if ($u['role'] === 'scale') redirect('scale.php');

$today = date('Y-m-d');
$prod = q("SELECT COUNT(*) n, COALESCE(SUM(net),0) net FROM packages WHERE status<>'void' AND DATE(created_at)=?", [$today])->fetch();
$byItem = q("SELECT i.code, i.name, COUNT(*) n, SUM(p.net) net FROM packages p JOIN items i ON i.id=p.finished_item_id
             WHERE p.status<>'void' AND DATE(p.created_at)=? GROUP BY i.id ORDER BY net DESC", [$today])->fetchAll();
$totals = q("SELECT i.type, COALESCE(SUM(m.qty),0) qty FROM items i LEFT JOIN stock_moves m ON m.item_id=i.id GROUP BY i.type")->fetchAll(PDO::FETCH_KEY_PAIR);
$low = q("SELECT i.code, i.name, COALESCE(SUM(m.qty),0) qty FROM items i LEFT JOIN stock_moves m ON m.item_id=i.id
          WHERE i.active=1 AND i.type IN ('cone','pack') GROUP BY i.id HAVING qty < 200 ORDER BY qty")->fetchAll();

$pageTitle = 'الرئيسية';
require __DIR__ . '/inc/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card kpi p-3"><div class="text-muted">إنتاج النهارده (كيلو)</div><div class="value num"><?= fmt_qty($prod['net'], 1) ?></div><div class="small text-muted"><?= (int)$prod['n'] ?> كرتونة / شكارة</div></div></div>
  <div class="col-md-3"><div class="card kpi p-3"><div class="text-muted">رصيد الخام (كيلو)</div><div class="value num"><?= fmt_qty($totals['raw'] ?? 0, 1) ?></div></div></div>
  <div class="col-md-3"><div class="card kpi p-3"><div class="text-muted">رصيد الخيط التام (كيلو)</div><div class="value num"><?= fmt_qty($totals['finished'] ?? 0, 1) ?></div></div></div>
  <div class="col-md-3"><div class="card kpi p-3"><div class="text-muted">رصيد الكونات (قطعة)</div><div class="value num"><?= fmt_qty($totals['cone'] ?? 0, 0) ?></div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card p-3">
      <h6>إنتاج النهارده حسب النمرة</h6>
      <table class="table table-sm mb-0">
        <thead><tr><th>الكود</th><th>النمرة</th><th>عدد</th><th>صافي (كيلو)</th></tr></thead>
        <tbody>
        <?php foreach ($byItem as $r): ?>
          <tr><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><?= (int)$r['n'] ?></td><td class="num"><?= fmt_qty($r['net']) ?></td></tr>
        <?php endforeach; if (!$byItem): ?><tr><td colspan="4" class="text-muted">لسه مفيش إنتاج النهارده</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card p-3">
      <h6>كونات وكراتين قربت تخلص (أقل من 200)</h6>
      <table class="table table-sm mb-0">
        <?php foreach ($low as $r): ?>
          <tr><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td class="num <?= $r['qty'] < 0 ? 'neg' : '' ?>"><?= fmt_qty($r['qty'], 0) ?></td></tr>
        <?php endforeach; if (!$low): ?><tr><td class="text-muted">مفيش</td></tr><?php endif; ?>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
