<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
$u = require_role();
if ($u['role'] === 'scale') redirect('scale.php');

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$prod = q("SELECT COUNT(*) n, COALESCE(SUM(net),0) net FROM packages WHERE status<>'void' AND DATE(created_at)=?", [$today])->fetch();
$totals = q("SELECT i.type, COALESCE(SUM(m.qty),0) qty FROM items i LEFT JOIN stock_moves m ON m.item_id=i.id GROUP BY i.type")->fetchAll(PDO::FETCH_KEY_PAIR);
$inStock = q("SELECT COUNT(*) n, COALESCE(SUM(net),0) kg FROM packages WHERE status='in_stock'")->fetch();
$salesMonth = q("SELECT COALESCE(SUM(CASE WHEN type='sale' THEN total ELSE -total END),0) amt,
                        COALESCE(SUM(CASE WHEN type='sale' THEN 1 ELSE 0 END),0) n FROM sales_invoices WHERE voided=0 AND inv_date>=?", [$monthStart])->fetch();
$receiptsMonth = (float)q("SELECT COALESCE(SUM(amount),0) FROM payments WHERE voided=0 AND type='receipt' AND pay_date>=?", [$monthStart])->fetchColumn();
$receivable = 0.0;
foreach (q("SELECT id FROM parties WHERE type='customer'")->fetchAll(PDO::FETCH_COLUMN) as $pid) { $b = party_balance((int)$pid); if ($b > 0) $receivable += $b; }

// last 14 days production and sales (kg)
$days = [];
for ($i = 13; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = ['prod' => 0, 'sold' => 0];
foreach (q("SELECT DATE(created_at) d, SUM(net) kg FROM packages WHERE status<>'void' AND created_at >= ? GROUP BY DATE(created_at)", [array_key_first($days)])->fetchAll() as $r) $days[$r['d']]['prod'] = round((float)$r['kg'], 1);
foreach (q("SELECT s.inv_date d, SUM(CASE WHEN s.type='sale' THEN l.qty ELSE -l.qty END) kg FROM sales_invoices s JOIN sales_lines l ON l.invoice_id=s.id JOIN items i ON i.id=l.item_id
            WHERE s.voided=0 AND i.type='finished' AND s.inv_date >= ? GROUP BY s.inv_date", [array_key_first($days)])->fetchAll() as $r) if (isset($days[$r['d']])) $days[$r['d']]['sold'] = round((float)$r['kg'], 1);
$byItem = q("SELECT i.code, i.name, COUNT(*) n, SUM(p.net) net FROM packages p JOIN items i ON i.id=p.finished_item_id WHERE p.status<>'void' AND DATE(p.created_at)=? GROUP BY i.id ORDER BY net DESC", [$today])->fetchAll();
$stockFinished = q("SELECT i.code, i.name, COALESCE(SUM(m.qty),0) kg FROM items i LEFT JOIN stock_moves m ON m.item_id=i.id WHERE i.type='finished' AND i.active=1 GROUP BY i.id HAVING kg > 0.0005 ORDER BY kg DESC LIMIT 8")->fetchAll();
$low = q("SELECT i.code, i.name, COALESCE(SUM(m.qty),0) qty FROM items i LEFT JOIN stock_moves m ON m.item_id=i.id
          WHERE i.active=1 AND i.type IN ('cone','pack') GROUP BY i.id HAVING qty < 200 ORDER BY qty")->fetchAll();
$recentSales = q("SELECT s.id, s.type, s.number, s.inv_date, s.total, p.name FROM sales_invoices s JOIN parties p ON p.id=s.customer_id WHERE s.voided=0 ORDER BY s.id DESC LIMIT 6")->fetchAll();

$pageTitle = 'لوحة التحكم';
$pageScripts = ['assets/chart.umd.min.js'];
require __DIR__ . '/inc/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3 reveal"><div class="card kpi card-hover"><div class="ic g-cyan"><i class="bi bi-speedometer"></i></div>
    <div><div class="lbl">إنتاج النهارده</div><div class="value num"><span data-count="<?= round($prod['net'], 1) ?>" data-dec="1">0</span> <small class="fs-6 text-muted">كيلو</small></div><div class="sub"><?= (int)$prod['n'] ?> كرتونة / شكارة</div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal"><div class="card kpi card-hover"><div class="ic g-blue"><i class="bi bi-box-seam"></i></div>
    <div><div class="lbl">خيط تام في المخزن</div><div class="value num"><span data-count="<?= round($totals['finished'] ?? 0, 1) ?>" data-dec="1">0</span> <small class="fs-6 text-muted">كيلو</small></div><div class="sub"><?= (int)$inStock['n'] ?> كرتونة بالباركود</div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal"><div class="card kpi card-hover"><div class="ic g-green"><i class="bi bi-graph-up-arrow"></i></div>
    <div><div class="lbl">مبيعات الشهر</div><div class="value num"><span data-count="<?= round($salesMonth['amt'], 0) ?>">0</span></div><div class="sub"><?= (int)$salesMonth['n'] ?> فاتورة — تحصيل <?= fmt_qty($receiptsMonth, 0) ?></div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal"><div class="card kpi card-hover"><div class="ic g-red"><i class="bi bi-wallet2"></i></div>
    <div><div class="lbl">مستحق على العملاء</div><div class="value num"><span data-count="<?= round($receivable, 0) ?>">0</span></div><div class="sub"><a href="balances.php">عرض الأرصدة</a></div></div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3 reveal"><div class="card kpi"><div class="ic g-amber"><i class="bi bi-layers"></i></div>
    <div><div class="lbl">رصيد الخام</div><div class="value num"><span data-count="<?= round($totals['raw'] ?? 0, 1) ?>" data-dec="1">0</span> <small class="fs-6 text-muted">كيلو</small></div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal"><div class="card kpi"><div class="ic g-violet"><i class="bi bi-circle-square"></i></div>
    <div><div class="lbl">رصيد الكونات</div><div class="value num"><span data-count="<?= round($totals['cone'] ?? 0) ?>">0</span> <small class="fs-6 text-muted">قطعة</small></div></div></div></div>
  <div class="col-xl-6 reveal"><div class="card p-3 h-100 d-flex flex-row flex-wrap gap-2 align-items-center">
    <span class="fw-bold me-2"><i class="bi bi-lightning-charge text-warning"></i> اختصارات:</span>
    <a href="sale_new.php" class="btn btn-success btn-sm"><i class="bi bi-upc-scan"></i> فاتورة بيع</a>
    <a href="payments.php" class="btn btn-outline-success btn-sm"><i class="bi bi-cash-coin"></i> سند قبض</a>
    <a href="doc_new.php?type=receipt" class="btn btn-outline-primary btn-sm"><i class="bi bi-truck"></i> استلام خام</a>
    <a href="doc_new.php?type=issue" class="btn btn-outline-warning btn-sm"><i class="bi bi-gear-wide-connected"></i> صرف للتشغيل</a>
    <a href="stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-boxes"></i> المخزن</a>
  </div></div>
</div>
<div class="row g-3">
  <div class="col-xl-8 reveal">
    <div class="card p-3 h-100"><div class="card-title-row"><h6><i class="bi bi-bar-chart"></i> الإنتاج والمبيعات — آخر 14 يوم (كيلو)</h6></div>
      <div style="height:300px"><canvas id="chartDays"></canvas></div></div>
  </div>
  <div class="col-xl-4 reveal">
    <div class="card p-3 h-100"><div class="card-title-row"><h6><i class="bi bi-pie-chart"></i> المخزن حسب النمرة</h6></div>
      <?php if ($stockFinished): ?><div style="height:300px"><canvas id="chartStock"></canvas></div><?php else: ?><div class="text-muted text-center py-5"><i class="bi bi-inbox fs-2 d-block"></i>المخزن فاضي</div><?php endif; ?></div>
  </div>
  <div class="col-xl-5 reveal">
    <div class="card table-wrap h-100"><div class="p-3 pb-0 card-title-row"><h6><i class="bi bi-speedometer"></i> إنتاج النهارده حسب النمرة</h6></div>
      <table class="table table-sm"><thead><tr><th>الكود</th><th>النمرة</th><th>عدد</th><th>صافي</th></tr></thead>
      <?php foreach ($byItem as $r): ?><tr><td><?= e($r['code']) ?></td><td class="small"><?= e($r['name']) ?></td><td><?= (int)$r['n'] ?></td><td class="num fw-bold"><?= fmt_qty($r['net'], 1) ?></td></tr><?php endforeach; ?>
      <?php if (!$byItem): ?><tr><td colspan="4" class="text-muted text-center py-3">لسه مفيش إنتاج النهارده</td></tr><?php endif; ?></table></div>
  </div>
  <div class="col-xl-4 reveal">
    <div class="card table-wrap h-100"><div class="p-3 pb-0 card-title-row"><h6><i class="bi bi-receipt"></i> آخر الفواتير</h6><a href="sales.php" class="small">الكل</a></div>
      <table class="table table-sm"><?php foreach ($recentSales as $s): ?>
        <tr><td><span class="badge-soft <?= $s['type'] === 'sale' ? 'ok' : 'warn' ?>"><?= $s['type'] === 'sale' ? 'بيع' : 'مرتجع' ?> <?= (int)$s['number'] ?></span></td><td><?= e($s['name']) ?></td><td class="num fw-bold"><a href="sale_view.php?id=<?= $s['id'] ?>"><?= fmt_qty($s['total'], 0) ?></a></td></tr>
      <?php endforeach; if (!$recentSales): ?><tr><td class="text-muted text-center py-3">لسه مفيش فواتير</td></tr><?php endif; ?></table></div>
  </div>
  <div class="col-xl-3 reveal">
    <div class="card table-wrap h-100"><div class="p-3 pb-0 card-title-row"><h6><i class="bi bi-exclamation-triangle text-warning"></i> قربت تخلص</h6></div>
      <table class="table table-sm"><?php foreach ($low as $r): ?>
        <tr><td class="small"><?= e($r['name']) ?></td><td class="num fw-bold <?= $r['qty'] < 0 ? 'neg' : '' ?>"><?= fmt_qty($r['qty'], 0) ?></td></tr>
      <?php endforeach; if (!$low): ?><tr><td class="text-muted text-center py-3">كله تمام</td></tr><?php endif; ?></table></div>
  </div>
</div>
<script>
window.addEventListener('load', () => {
  Chart.defaults.font.family = 'Cairo, Tahoma, sans-serif';
  Chart.defaults.color = '#64748b';
  const days = <?= json_encode(array_map(fn($d) => date('d/m', strtotime($d)), array_keys($days))) ?>;
  new Chart(document.getElementById('chartDays'), {
    type: 'bar',
    data: { labels: days, datasets: [
      { label: 'الإنتاج', data: <?= json_encode(array_column($days, 'prod')) ?>, backgroundColor: 'rgba(34,184,216,.85)', borderRadius: 6 },
      { label: 'المبيعات', data: <?= json_encode(array_column($days, 'sold')) ?>, backgroundColor: 'rgba(16,185,129,.85)', borderRadius: 6 },
    ]},
    options: { maintainAspectRatio: false, animation: { duration: 1200, easing: 'easeOutQuart' }, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, grid: { color: '#eef2f7' } }, x: { grid: { display: false } } } },
  });
  const sc = document.getElementById('chartStock');
  if (sc) new Chart(sc, {
    type: 'doughnut',
    data: { labels: <?= json_encode(array_column($stockFinished, 'code')) ?>, datasets: [{ data: <?= json_encode(array_map(fn($r) => round((float)$r['kg'], 1), $stockFinished)) ?>,
      backgroundColor: ['#22b8d8', '#2563eb', '#10b981', '#f59e0b', '#a78bfa', '#ef4444', '#14b8a6', '#64748b'], borderWidth: 2 }] },
    options: { maintainAspectRatio: false, cutout: '62%', animation: { animateRotate: true, duration: 1300 }, plugins: { legend: { position: 'bottom' } } },
  });
});
</script>
<?php require __DIR__ . '/inc/footer.php';
