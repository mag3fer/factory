<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/stock.php';
$u = require_role('store');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    csrf_check();
    if ($u['role'] !== 'admin') { flash('الإلغاء للمدير بس', 'danger'); redirect("doc_view.php?id=$id"); }
    void_doc($id);
    flash('تم إلغاء المستند وحذف أثره من المخزن', 'warning');
    redirect("doc_view.php?id=$id");
}
$d = q('SELECT d.*, p.name party, us.full_name usr FROM docs d LEFT JOIN parties p ON p.id=d.party_id JOIN users us ON us.id=d.user_id WHERE d.id=?', [$id])->fetch();
if (!$d) { http_response_code(404); exit('غير موجود'); }
$lines = q('SELECT l.*, i.code, i.name, i.unit FROM doc_lines l JOIN items i ON i.id=l.item_id WHERE l.doc_id=? ORDER BY l.id', [$id])->fetchAll();

$pageTitle = DOC_TYPES[$d['type']] . ' ' . $d['number'];
require __DIR__ . '/inc/header.php';
?>
<div class="card p-3">
  <div class="d-flex justify-content-between">
    <h4><?= DOC_TYPES[$d['type']] ?> رقم <?= (int)$d['number'] ?> <?php if ($d['voided']): ?><span class="badge bg-danger">ملغي</span><?php endif; ?></h4>
    <div class="no-print"><button onclick="print()" class="btn btn-outline-secondary btn-sm">طباعة</button>
      <?php if (!$d['voided'] && $u['role'] === 'admin'): ?>
      <form method="post" class="d-inline" onsubmit="return confirm('إلغاء المستند هيشيل الكميات دي من المخزن. متأكد؟')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="void">
        <button class="btn btn-outline-danger btn-sm">إلغاء المستند</button></form><?php endif; ?></div>
  </div>
  <p class="mb-2">التاريخ: <?= e($d['doc_date']) ?><?php if ($d['party']): ?> — المورد: <?= e($d['party']) ?><?php endif; ?><?php if ($d['ref_no']): ?> — رقم المورد: <?= e($d['ref_no']) ?><?php endif; ?> — بواسطة: <?= e($d['usr']) ?></p>
  <?php if ($d['notes']): ?><p>ملاحظات: <?= e($d['notes']) ?></p><?php endif; ?>
  <table class="table table-sm table-bordered">
    <thead><tr><th>الكود</th><th>الصنف</th><th>بالتات</th><th>الكمية</th><th>العدد</th><th>السعر</th><th>الإجمالي</th><th>ملاحظات</th></tr></thead>
    <?php $sum = 0; $val = 0; foreach ($lines as $l): $sum += $l['qty']; $val += (float)$l['qty'] * (float)$l['price']; ?>
      <tr><td><?= e($l['code']) ?></td><td><?= e($l['name']) ?></td><td><?= $l['pallets'] ?? '' ?></td>
        <td class="num"><?= fmt_qty($l['qty'], $l['unit'] === 'kg' ? 3 : 0) ?> <?= UNITS[$l['unit']] ?></td><td class="num"><?= (int)$l['pieces'] ?: '' ?></td>
        <td class="num"><?= $l['price'] !== null ? fmt_qty($l['price'], 2) : '' ?></td><td class="num"><?= $l['price'] !== null ? fmt_qty((float)$l['qty'] * (float)$l['price'], 2) : '' ?></td><td><?= e($l['notes']) ?></td></tr>
    <?php endforeach; ?>
    <tr class="fw-bold"><td colspan="3">الإجمالي</td><td class="num"><?= fmt_qty($sum) ?></td><td></td><td></td><td class="num"><?= $val ? fmt_qty($val, 2) : '' ?></td><td></td></tr>
  </table>
  <a href="docs.php" class="btn btn-secondary no-print">رجوع</a>
</div>
<?php require __DIR__ . '/inc/footer.php';
