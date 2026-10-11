<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
require_role('store');

$type = ($_GET['type'] ?? '') === 'supplier' ? 'supplier' : 'customer';
$hideZero = !isset($_GET['all']);
$rows = [];
foreach (q('SELECT * FROM parties WHERE type=? ORDER BY name', [$type])->fetchAll() as $p) {
    $bal = party_balance((int)$p['id']);
    if ($hideZero && abs($bal) < 0.005) continue;
    // aging: how old is the oldest unpaid sale (FIFO: payments settle the oldest invoices first)
    $age = null;
    if ($bal > 0 && $type === 'customer') {
        $remaining = $bal;
        foreach (q("SELECT inv_date, total FROM sales_invoices WHERE customer_id=? AND type='sale' AND voided=0 ORDER BY inv_date DESC, id DESC", [$p['id']])->fetchAll() as $s) {
            $remaining -= (float)$s['total'];
            $age = $s['inv_date'];
            if ($remaining <= 0.005) break;
        }
    }
    $last = q("SELECT MAX(d) FROM (SELECT MAX(inv_date) d FROM sales_invoices WHERE customer_id=? AND voided=0 UNION ALL SELECT MAX(pay_date) FROM payments WHERE party_id=? AND voided=0) x", [$p['id'], $p['id']])->fetchColumn();
    $rows[] = $p + ['bal' => $bal, 'oldest' => $age, 'last' => $last];
}
usort($rows, fn($a, $b) => $b['bal'] <=> $a['bal']);
$owe = array_sum(array_map(fn($r) => max(0, $r['bal']), $rows));
$credit = array_sum(array_map(fn($r) => max(0, -$r['bal']), $rows));
$pageTitle = $type === 'customer' ? 'أرصدة العملاء' : 'أرصدة الموردين';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <h4><i class="bi bi-people"></i> <?= $pageTitle ?></h4>
  <div class="d-flex gap-2 no-print">
    <a class="btn btn-sm <?= $type === 'customer' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?type=customer">العملاء</a>
    <a class="btn btn-sm <?= $type === 'supplier' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?type=supplier">الموردين</a>
    <a class="btn btn-sm btn-outline-secondary" href="?type=<?= $type ?><?= $hideZero ? '&all=1' : '' ?>"><?= $hideZero ? 'عرض الكل' : 'إخفاء الصفر' ?></a>
    <button onclick="print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i></button>
  </div>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-6 reveal"><div class="card kpi"><div class="ic g-red"><i class="bi bi-arrow-down-left-circle"></i></div><div><div class="lbl"><?= $type === 'customer' ? 'إجمالي اللي على العملاء' : 'دفعنا للموردين زيادة' ?></div><div class="value num"><span data-count="<?= round($owe, 2) ?>" data-dec="2">0</span></div></div></div></div>
  <div class="col-md-6 reveal"><div class="card kpi"><div class="ic g-green"><i class="bi bi-arrow-up-right-circle"></i></div><div><div class="lbl"><?= $type === 'customer' ? 'رصيد دائن للعملاء (ليهم)' : 'إجمالي اللي علينا للموردين' ?></div><div class="value num"><span data-count="<?= round($credit, 2) ?>" data-dec="2">0</span></div></div></div></div>
</div>
<div class="card table-wrap reveal">
<table class="table table-hover">
  <thead><tr><th>الكود</th><th>الاسم</th><th>الرصيد</th><th></th><th>أقدم فاتورة مش متسددة</th><th>آخر حركة</th><th class="no-print"></th></tr></thead>
  <?php foreach ($rows as $r): $days = $r['oldest'] ? (int)((time() - strtotime($r['oldest'])) / 86400) : null; ?>
    <tr><td><?= e($r['code']) ?></td><td class="fw-bold"><?= e($r['name']) ?></td>
      <td class="num fw-bold <?= $r['bal'] > 0 ? 'text-danger' : 'text-success' ?>"><?= fmt_qty(abs($r['bal']), 2) ?></td>
      <td><?= $r['bal'] > 0 ? 'عليه' : ($r['bal'] < 0 ? 'له' : '') ?></td>
      <td><?php if ($days !== null): ?><?= e($r['oldest']) ?> <span class="badge-soft <?= $days > 60 ? 'bad' : ($days > 30 ? 'warn' : 'info') ?>"><?= $days ?> يوم</span><?php endif; ?></td>
      <td><?= e($r['last']) ?></td>
      <td class="no-print text-nowrap"><a class="btn btn-sm btn-outline-primary" href="party_statement.php?id=<?= $r['id'] ?>"><i class="bi bi-journal-text"></i> كشف حساب</a>
        <a class="btn btn-sm btn-outline-success" href="payments.php?party=<?= $r['id'] ?>&type=<?= $type === 'customer' ? 'receipt' : 'payment' ?>"><i class="bi bi-cash"></i></a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">مفيش أرصدة</td></tr><?php endif; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
