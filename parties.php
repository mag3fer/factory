<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? '';
    $code = trim($_POST['code'] ?? '') ?: null;
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;
    $active = isset($_POST['active']) ? 1 : 0;
    if ($name === '' || !in_array($type, ['supplier', 'customer'], true)) {
        flash('الاسم والنوع مطلوبين', 'danger');
        redirect('parties.php');
    }
    try {
        if ($id) q('UPDATE parties SET code=?, name=?, type=?, phone=?, notes=?, active=? WHERE id=?', [$code, $name, $type, $phone, $notes, $active, $id]);
        else q('INSERT INTO parties (code, name, type, phone, notes, active) VALUES (?,?,?,?,?,?)', [$code, $name, $type, $phone, $notes, $active]);
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
    <div class="col-md-2"><input name="code" class="form-control" placeholder="الكود" value="<?= e($edit['code'] ?? '') ?>"></div>
    <div class="col-md-3"><input name="name" class="form-control" placeholder="الاسم" required value="<?= e($edit['name'] ?? '') ?>"></div>
    <div class="col-md-2"><select name="type" class="form-select"><option value="customer"<?= ($edit['type'] ?? $type) === 'customer' ? ' selected' : '' ?>>عميل</option><option value="supplier"<?= ($edit['type'] ?? $type) === 'supplier' ? ' selected' : '' ?>>مورد</option></select></div>
    <div class="col-md-2"><input name="phone" class="form-control" placeholder="الموبايل" value="<?= e($edit['phone'] ?? '') ?>"></div>
    <div class="col-md-2"><input name="notes" class="form-control" placeholder="ملاحظات" value="<?= e($edit['notes'] ?? '') ?>"></div>
    <div class="col-md-1 d-flex align-items-center gap-2"><input type="checkbox" name="active" class="form-check-input"<?= ($edit['active'] ?? 1) ? ' checked' : '' ?>> نشط</div>
    <div class="col-12"><button class="btn btn-success"><?= $edit ? 'حفظ التعديل' : '+ إضافة' ?></button><?php if ($edit): ?> <a href="parties.php?type=<?= $type ?>" class="btn btn-secondary">إلغاء</a><?php endif; ?></div>
  </form>
</div>
<div class="card p-0">
<table class="table table-sm table-hover mb-0">
  <thead class="table-light"><tr><th>الكود</th><th>الاسم</th><th>الموبايل</th><th>ملاحظات</th><th></th></tr></thead>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['active'] ? '' : 'text-muted' ?>"><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><?= e($r['phone']) ?></td><td><?= e($r['notes']) ?></td>
      <td><a class="btn btn-sm btn-outline-primary" href="?type=<?= $type ?>&edit=<?= $r['id'] ?>">تعديل</a></td></tr>
  <?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
