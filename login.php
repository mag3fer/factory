<?php
require __DIR__ . '/inc/bootstrap.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = q('SELECT * FROM users WHERE username=? AND active=1', [trim($_POST['username'] ?? '')])->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role']];
        redirect($user['role'] === 'scale' ? 'scale.php' : 'index.php');
    }
    $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
}
$pageTitle = 'تسجيل الدخول';
require __DIR__ . '/inc/header.php';
?>
<div class="row justify-content-center mt-5">
  <div class="col-md-4">
    <div class="card p-4">
      <h4 class="mb-3 text-center"><?= e(setting('company_name', 'المصنع')) ?></h4>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">اسم المستخدم</label><input name="username" class="form-control" required autofocus></div>
        <div class="mb-3"><label class="form-label">كلمة المرور</label><input name="password" type="password" class="form-control" required></div>
        <button class="btn btn-primary w-100">دخول</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
