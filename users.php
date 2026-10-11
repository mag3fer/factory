<?php
require __DIR__ . '/inc/bootstrap.php';
$me = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? '';
    $pass = (string)($_POST['password'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;
    if ($username === '' || $name === '' || !isset(ROLE_NAMES[$role])) { flash('كل البيانات مطلوبة', 'danger'); redirect('users.php'); }
    if (!$id && strlen($pass) < 6) { flash('كلمة المرور 6 حروف على الأقل', 'danger'); redirect('users.php'); }
    if ($id === $me['id'] && (!$active || $role !== 'admin')) { flash('مينفعش تشيل صلاحية المدير من نفسك', 'danger'); redirect('users.php'); }
    try {
        if ($id) {
            q('UPDATE users SET username=?, full_name=?, role=?, active=? WHERE id=?', [$username, $name, $role, $active, $id]);
            if ($pass !== '') {
                if (strlen($pass) < 6) { flash('كلمة المرور 6 حروف على الأقل', 'danger'); redirect("users.php?edit=$id"); }
                q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
        } else {
            q('INSERT INTO users (username, full_name, password_hash, role, active) VALUES (?,?,?,?,?)', [$username, $name, password_hash($pass, PASSWORD_DEFAULT), $role, $active]);
        }
        flash('تم الحفظ');
    } catch (PDOException $ex) {
        flash('اسم المستخدم موجود قبل كده', 'danger');
    }
    redirect('users.php');
}
$edit = isset($_GET['edit']) ? q('SELECT * FROM users WHERE id=?', [(int)$_GET['edit']])->fetch() : null;
$users = q('SELECT * FROM users ORDER BY id')->fetchAll();
$pageTitle = 'المستخدمين';
require __DIR__ . '/inc/header.php';
?>
<h4>المستخدمين</h4>
<div class="card p-3 mb-3">
  <form method="post" class="row g-2" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="col-md-2"><input name="username" class="form-control" placeholder="اسم الدخول" required value="<?= e($edit['username'] ?? '') ?>"></div>
    <div class="col-md-3"><input name="full_name" class="form-control" placeholder="الاسم" required value="<?= e($edit['full_name'] ?? '') ?>"></div>
    <div class="col-md-2"><select name="role" class="form-select"><?php foreach (ROLE_NAMES as $k => $l): ?><option value="<?= $k ?>"<?= ($edit['role'] ?? 'scale') === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><input name="password" type="password" class="form-control" placeholder="<?= $edit ? 'كلمة مرور جديدة (سيبها فاضية لو مش هتغيرها)' : 'كلمة المرور' ?>" autocomplete="new-password"></div>
    <div class="col-md-1 d-flex align-items-center gap-1"><input type="checkbox" name="active" class="form-check-input"<?= ($edit['active'] ?? 1) ? ' checked' : '' ?>> نشط</div>
    <div class="col-md-1"><button class="btn btn-success w-100"><?= $edit ? 'حفظ' : 'إضافة' ?></button></div>
  </form>
  <div class="form-text">الصلاحيات: <b>مدير</b> كل حاجة — <b>أمين مخزن</b> الأصناف والمستندات والمخزن — <b>عامل ميزان</b> شاشة الميزان بس.</div>
</div>
<div class="card p-0">
<table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>اسم الدخول</th><th>الاسم</th><th>الصلاحية</th><th>الحالة</th><th></th></tr></thead>
  <?php foreach ($users as $x): ?>
    <tr><td><?= e($x['username']) ?></td><td><?= e($x['full_name']) ?></td><td><?= ROLE_NAMES[$x['role']] ?></td><td><?= $x['active'] ? 'نشط' : 'موقوف' ?></td>
      <td><a class="btn btn-sm btn-outline-primary" href="?edit=<?= $x['id'] ?>">تعديل</a></td></tr>
  <?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
