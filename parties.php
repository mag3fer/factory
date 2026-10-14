<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('store');

/** Next free code for a customer / supplier: the last numeric code of that type + 1 (same width). */
function next_party_code(string $type): string
{
    $last = q("SELECT code FROM parties WHERE type=? AND code REGEXP '^[0-9]+$' ORDER BY CAST(code AS UNSIGNED) DESC LIMIT 1", [$type])->fetchColumn();
    return $last ? str_pad((string)((int)$last + 1), strlen($last), '0', STR_PAD_LEFT) : ($type === 'supplier' ? '22030001' : '12030001');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    $code = trim($_POST['code'] ?? '') ?: null;
    if (!$id && $code === null && in_array($type, ['supplier', 'customer'], true)) $code = next_party_code($type);
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;
    $active = isset($_POST['active']) ? 1 : 0;
    $ob = round((float)($_POST['opening_balance'] ?? 0), 2) * (($_POST['ob_side'] ?? 'owes') === 'owes' ? 1 : -1);
    $obDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['opening_date'] ?? '') ? $_POST['opening_date'] : null;
    if ($name === '' || !in_array($type, ['supplier', 'customer'], true)) {
        flash('الاسم والنوع مطلوبين', 'danger');
        redirect('parties.php');
    }
    try {
        if ($id) q('UPDATE parties SET code=?, name=?, type=?, phone=?, notes=?, active=?, opening_balance=?, opening_date=? WHERE id=?', [$code, $name, $type, $phone, $notes, $active, $ob, $obDate, $id]);
        else q('INSERT INTO parties (code, name, type, phone, notes, active, opening_balance, opening_date) VALUES (?,?,?,?,?,?,?,?)', [$code, $name, $type, $phone, $notes, $active, $ob, $obDate]);
        flash('تم الحفظ');
    } catch (PDOException $ex) {
        flash('الكود ده مستخدم قبل كده', 'danger');
    }
    redirect('parties.php?type=' . $type);
}

$type = in_array($_GET['type'] ?? '', ['supplier', 'customer'], true) ? $_GET['type'] : 'customer';
$edit = isset($_GET['edit']) ? q('SELECT * FROM parties WHERE id=?', [(int)$_GET['edit']])->fetch() : null;
$rows = q('SELECT * FROM parties WHERE type=? ORDER BY code, name', [$type])->fetchAll();
$pageTitle = 'العملاء والموردين';
require __DIR__ . '/inc/header.php';
?>
<h4>العملاء والموردين</h4>
<ul class="nav nav-pills mb-2">
  <li class="nav-item"><a class="nav-link<?= $type === 'customer' ? ' active' : '' ?>" href="?type=customer">العملاء</a></li>
  <li class="nav-item"><a class="nav-link<?= $type === 'supplier' ? ' active' : '' ?>" href="?type=supplier">الموردين</a></li>
</ul>
<div class="card p-3 mb-3">
  <form method="post" class="row g-2">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="col-md-2"><input name="code" id="partyCode" class="form-control" placeholder="الكود" value="<?= e($edit ? ($edit['code'] ?? '') : next_party_code($type)) ?>"
      data-next-customer="<?= e(next_party_code('customer')) ?>" data-next-supplier="<?= e(next_party_code('supplier')) ?>"></div>
    <div class="col-md-3"><input name="name" class="form-control" placeholder="الاسم" required value="<?= e($edit['name'] ?? '') ?>"></div>
    <div class="col-md-2"><select name="type" id="partyType" class="form-select"><option value="customer"<?= ($edit['type'] ?? $type) === 'customer' ? ' selected' : '' ?>>عميل</option><option value="supplier"<?= ($edit['type'] ?? $type) === 'supplier' ? ' selected' : '' ?>>مورد</option></select></div>
    <div class="col-md-2"><input name="phone" class="form-control" placeholder="الموبايل" value="<?= e($edit['phone'] ?? '') ?>"></div>
    <div class="col-md-2"><input name="notes" class="form-control" placeholder="ملاحظات" value="<?= e($edit['notes'] ?? '') ?>"></div>
    <div class="col-md-1 d-flex align-items-center gap-2"><input type="checkbox" name="active" class="form-check-input"<?= ($edit['active'] ?? 1) ? ' checked' : '' ?>> نشط</div>
    <?php $ob = (float)($edit['opening_balance'] ?? 0); ?>
    <div class="col-md-3"><div class="input-group"><span class="input-group-text">رصيد افتتاحي</span><input name="opening_balance" type="number" step="0.01" min="0" class="form-control num" value="<?= $ob ? e((string)abs($ob)) : '' ?>"></div></div>
    <div class="col-md-2"><select name="ob_side" class="form-select"><option value="owes"<?= $ob >= 0 ? ' selected' : '' ?>>عليه (مديون لنا)</option><option value="credit"<?= $ob < 0 ? ' selected' : '' ?>>له (احنا مديونين له)</option></select></div>
    <div class="col-md-2"><input name="opening_date" type="date" class="form-control" title="تاريخ الرصيد الافتتاحي" value="<?= e($edit['opening_date'] ?? '') ?>"></div>
    <div class="col-12"><button class="btn btn-success"><?= $edit ? 'حفظ التعديل' : '+ إضافة' ?></button><?php if ($edit): ?> <a href="parties.php?type=<?= $type ?>" class="btn btn-secondary">إلغاء</a><?php endif; ?></div>
  </form>
</div>
<div class="card p-0">
<table class="table table-sm table-hover mb-0">
  <thead class="table-light"><tr><th>الكود</th><th>الاسم</th><th>الموبايل</th><th>رصيد افتتاحي</th><th>ملاحظات</th><th></th></tr></thead>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['active'] ? '' : 'text-muted' ?>"><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><?= e($r['phone']) ?></td><td class="num"><?= (float)$r['opening_balance'] ? fmt_qty($r['opening_balance'], 2) : '' ?></td><td><?= e($r['notes']) ?></td>
      <td><a class="btn btn-sm btn-outline-primary" href="?type=<?= $type ?>&edit=<?= $r['id'] ?>">تعديل</a></td></tr>
  <?php endforeach; ?>
</table>
</div>
<?php if (!$edit): ?>
<script>
// new customer/supplier: switching the type swaps in that type's next code (unless one was typed by hand)
(() => {
  const code = document.getElementById('partyCode'), type = document.getElementById('partyType');
  type.addEventListener('change', () => {
    const other = type.value === 'customer' ? code.dataset.nextSupplier : code.dataset.nextCustomer;
    if (code.value === '' || code.value === other) code.value = type.value === 'customer' ? code.dataset.nextCustomer : code.dataset.nextSupplier;
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
