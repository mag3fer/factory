<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hr.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$r = q("SELECT l.*, w.name, w.role, w.job FROM hr_ledger l JOIN workers w ON w.id=l.worker_id WHERE l.id=? AND l.kind='payment'", [$id])->fetch();
if (!$r) { http_response_code(404); exit('غير موجود'); }
$bal = hr_balance((int)$r['worker_id']);
$logo = setting('logo_file');
$pageTitle = 'إيصال صرف رقم ' . $id;
require __DIR__ . '/inc/header.php';
?>
<style>
.rc { max-width: 760px; margin: 0 auto; } .rc-title { text-align: center; margin: .4rem 0 1rem; } .rc-title span { display: inline-block; background: #d0d3d8; border: 1px solid #333; padding: .15rem 3rem; font-size: 1.5rem; font-weight: 800; }
.rc table { width: 100%; border-collapse: collapse; } .rc td { padding: .45rem .5rem; border-bottom: 1px solid #bbb; } .rc .lbl { width: 28%; color: #222; }
.rc-sign { display: flex; justify-content: space-between; margin-top: 4.5rem; padding: 0 2rem; } .rc-sign div { text-align: center; min-width: 200px; } .rc-sign .line { border-top: 1px solid #333; margin-top: 3rem; padding-top: .2rem; }
@page { size: A4; margin: 0; }
@media print { body { margin: 0; color: #000 !important; } .card.rc-card { padding: 16mm 14mm !important; box-shadow: none; } .rc-card, .rc-card * { color: #000 !important; font-weight: 600; } .rc-title span { background: #d0d3d8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
<div class="rc">
  <div class="page-head no-print"><h4>إيصال صرف</h4><div class="d-flex gap-2"><a href="hr_statement.php?id=<?= (int)$r['worker_id'] ?>" class="btn btn-secondary"><i class="bi bi-arrow-right"></i> كشف الحساب</a><button onclick="print()" class="btn btn-primary"><i class="bi bi-printer"></i> طباعة</button></div></div>
  <div class="card p-4 rc-card">
    <div class="d-flex justify-content-between align-items-start">
      <div class="text-end" style="line-height:1.5"><div class="fs-5 fw-bold"><?= e(setting('company_name')) ?></div><div><?= e(setting('company_address')) ?></div><div class="num"><?= e(setting('company_phone1')) ?></div></div>
      <div><?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?><img src="<?= e($logo) ?>" style="height:78px" alt=""><?php else: ?><img src="assets/brand.svg" style="height:78px" alt=""><?php endif; ?></div>
    </div>
    <div class="rc-title"><span>إيصال صرف</span></div>
    <table>
      <tr><td class="lbl">رقم الإيصال</td><td><b><?= (int)$r['id'] ?></b></td></tr>
      <tr><td class="lbl">التاريخ</td><td><b class="num"><?= e(date('d/m/Y', strtotime($r['entry_date']))) ?></b></td></tr>
      <tr><td class="lbl">استلم السيد / الاسم</td><td><b><?= e($r['name']) ?></b> <span class="small"><?= e($r['job'] ?: WORKER_ROLES[$r['role']]) ?></span></td></tr>
      <tr><td class="lbl">المبلغ</td><td><b class="num fs-4"><?= money((float)$r['amount']) ?></b> جنيه</td></tr>
      <tr><td class="lbl">وذلك عن</td><td><?= e($r['notes'] ?: 'صرف مستحقات') ?></td></tr>
      <tr><td class="lbl">الرصيد بعد الصرف</td><td class="num"><?= money(abs($bal)) ?> <?= $bal > 0.004 ? 'مستحق له' : ($bal < -0.004 ? 'عليه' : '') ?></td></tr>
    </table>
    <div class="rc-sign"><div>المنظم<div class="line">&nbsp;</div></div><div>توقيع المستلم<div class="line">&nbsp;</div></div></div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
