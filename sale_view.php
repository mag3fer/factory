<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
$u = require_role('store');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    csrf_check();
    if ($u['role'] !== 'admin') { flash('الإلغاء للمدير بس', 'danger'); redirect("sale_view.php?id=$id"); }
    try { void_sale($id); flash('تم إلغاء الفاتورة: الكراتين رجعت لحالتها والمخزن اتعدل', 'warning'); }
    catch (RuntimeException $ex) { flash($ex->getMessage(), 'danger'); }
    redirect("sale_view.php?id=$id");
}
$inv = q('SELECT s.*, p.name customer, p.code ccode, p.phone, us.full_name usr FROM sales_invoices s JOIN parties p ON p.id=s.customer_id JOIN users us ON us.id=s.user_id WHERE s.id=?', [$id])->fetch();
if (!$inv) { http_response_code(404); exit('غير موجود'); }
$lines = q('SELECT l.*, i.code, i.name, i.unit FROM sales_lines l JOIN items i ON i.id=l.item_id WHERE l.invoice_id=? ORDER BY l.id', [$id])->fetchAll();
$cartons = q('SELECT k.serial, k.net, k.gross, k.cones_count, i.code FROM sales_packages sp JOIN packages k ON k.id=sp.package_id JOIN items i ON i.id=k.finished_item_id WHERE sp.invoice_id=? ORDER BY i.code, k.serial', [$id])->fetchAll();
$paidHere = (float)q('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND voided=0', [$id])->fetchColumn();
$balanceNow = party_balance((int)$inv['customer_id']);
$logo = setting('logo_file');
$isSale = $inv['type'] === 'sale';
$pageTitle = SALE_TYPES[$inv['type']] . ' رقم ' . $inv['number'];
require __DIR__ . '/inc/header.php';
?>
<style>
.inv { max-width: 900px; margin: 0 auto; }
.inv-head { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid var(--tl-navy-2); padding-bottom: .8rem; margin-bottom: 1rem; }
.inv-head img { height: 64px; }
.inv-head .co { font-size: 1.6rem; font-weight: 800; direction: ltr; }
.inv-meta td { padding: .2rem .6rem; }
.stamp-void { position: absolute; top: 30%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 5rem; font-weight: 800; color: rgba(239, 68, 68, .25); border: 8px solid rgba(239, 68, 68, .25); padding: 0 2rem; border-radius: 20px; pointer-events: none; }
@media print { .inv { max-width: none; } .card { padding: 0 !important; } }
</style>
<div class="inv">
  <div class="page-head no-print">
    <h4><?= e($pageTitle) ?> <?php if ($inv['voided']): ?><span class="badge-soft bad">ملغية</span><?php endif; ?></h4>
    <div class="d-flex gap-2">
      <button onclick="print()" class="btn btn-primary"><i class="bi bi-printer"></i> طباعة</button>
      <a href="sale_new.php?type=<?= $inv['type'] ?>" class="btn btn-success"><i class="bi bi-plus"></i> <?= $isSale ? 'فاتورة جديدة' : 'مرتجع جديد' ?></a>
      <a href="party_statement.php?id=<?= $inv['customer_id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-journal-text"></i> كشف حساب العميل</a>
      <?php if (!$inv['voided'] && $u['role'] === 'admin'): ?>
      <form method="post" onsubmit="return confirm('إلغاء الفاتورة هيرجّع الكراتين والمخزن ويلغي المدفوع المرتبط بيها. متأكد؟')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="void">
        <button class="btn btn-outline-danger"><i class="bi bi-x-octagon"></i> إلغاء</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php if (isset($_GET['new'])): ?><div class="alert alert-success no-print"><i class="bi bi-check-circle"></i> تم حفظ <?= SALE_TYPES[$inv['type']] ?> رقم <?= (int)$inv['number'] ?> بنجاح</div><?php endif; ?>
  <div class="card p-4 position-relative">
    <?php if ($inv['voided']): ?><div class="stamp-void">ملغية</div><?php endif; ?>
    <div class="inv-head">
      <div><div class="co"><?= e(setting('company_name')) ?></div><div class="text-muted small"><?= e(setting('company_address')) ?></div>
        <div class="text-muted small num"><?= e(setting('company_phone1')) ?> — <?= e(setting('company_phone2')) ?></div></div>
      <div class="text-center"><?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><img src="assets/brand.svg" alt=""><?php endif; ?>
        <div class="fw-bold mt-1"><?= SALE_TYPES[$inv['type']] ?></div></div>
    </div>
    <table class="inv-meta mb-3"><tr>
      <td class="text-muted">رقم:</td><td class="fw-bold"><?= (int)$inv['number'] ?></td>
      <td class="text-muted">التاريخ:</td><td class="fw-bold"><?= e($inv['inv_date']) ?></td>
      <td class="text-muted">العميل:</td><td class="fw-bold"><?= e($inv['customer']) ?> <?= $inv['ccode'] ? '(' . e($inv['ccode']) . ')' : '' ?></td>
    </tr></table>
    <table class="table table-bordered">
      <thead><tr><th>الكود</th><th>الصنف</th><th>عدد الكراتين</th><th>الكونات</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr></thead>
      <?php foreach ($lines as $l): ?>
        <tr><td><?= e($l['code']) ?></td><td><?= e($l['name']) ?></td><td><?= $l['packages_count'] ?: '' ?></td><td><?= $l['pieces'] ?: '' ?></td>
          <td class="num"><?= fmt_qty($l['qty'], $l['unit'] === 'kg' ? 3 : 0) ?> <?= UNITS[$l['unit']] ?></td><td class="num"><?= fmt_qty($l['price'], 2) ?></td><td class="num fw-bold"><?= fmt_qty($l['amount'], 2) ?></td></tr>
      <?php endforeach; ?>
      <tr><td colspan="6" class="text-start">الإجمالي</td><td class="num"><?= fmt_qty($inv['subtotal'], 2) ?></td></tr>
      <?php if ($inv['discount'] > 0): ?><tr><td colspan="6" class="text-start">خصم</td><td class="num">- <?= fmt_qty($inv['discount'], 2) ?></td></tr><?php endif; ?>
      <tr class="table-light"><td colspan="6" class="text-start fw-bold">الصافي</td><td class="num fw-bold fs-5"><?= fmt_qty($inv['total'], 2) ?></td></tr>
      <?php if ($paidHere > 0): ?><tr><td colspan="6" class="text-start"><?= $isSale ? 'المدفوع مع الفاتورة' : 'اترجع للعميل' ?></td><td class="num"><?= fmt_qty($paidHere, 2) ?></td></tr><?php endif; ?>
    </table>
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
      <div>رصيد العميل الحالي: <b class="num <?= $balanceNow > 0 ? 'text-danger' : 'text-success' ?>"><?= fmt_qty(abs($balanceNow), 2) ?></b> <?= $balanceNow > 0 ? 'عليه' : ($balanceNow < 0 ? 'له' : '') ?></div>
      <?php if ($inv['notes']): ?><div>ملاحظات: <?= e($inv['notes']) ?></div><?php endif; ?>
      <div class="text-muted small">بواسطة: <?= e($inv['usr']) ?> — <?= e(substr($inv['created_at'], 0, 16)) ?></div>
    </div>
    <?php if ($cartons): ?>
      <h6 class="fw-bold">بيان الكراتين (<?= count($cartons) ?>)</h6>
      <table class="table table-sm table-bordered small">
        <thead><tr><th>#</th><th>الباركود</th><th>الكود</th><th>كونات</th><th>قائم</th><th>صافي</th></tr></thead>
        <?php foreach ($cartons as $i => $c): ?><tr><td><?= $i + 1 ?></td><td class="num"><?= e($c['serial']) ?></td><td><?= e($c['code']) ?></td><td><?= (int)$c['cones_count'] ?></td><td class="num"><?= fmt_qty($c['gross'], 2) ?></td><td class="num"><?= fmt_qty($c['net'], 3) ?></td></tr><?php endforeach; ?>
      </table>
    <?php endif; ?>
    <div class="d-flex justify-content-between mt-4 pt-3" style="border-top:1px dashed #cbd5e1"><span>توقيع المستلم: ..................</span><span>توقيع المسؤول: ..................</span></div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
