<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

$keys = [
    'company_name' => ['اسم الشركة على الستيكر', 'text'],
    'company_phone1' => ['رقم الهاتف 1', 'text'],
    'company_phone2' => ['رقم الهاتف 2', 'text'],
    'company_address' => ['العنوان على الستيكر', 'text'],
    'bag_weight' => ['وزن الكيس لكل كونة (كيلو)', 'number'],
    'default_cones' => ['عدد الكونات الافتراضي في الكرتونة', 'number'],
    'label_width_mm' => ['عرض الستيكر (مم)', 'number'],
    'label_height_mm' => ['طول الستيكر (مم)', 'number'],
    'scale_baud' => ['سرعة بورت الميزان (Baud)', 'number'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $save = fn(string $k, string $v) => q('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)', [$k, $v]);
    foreach ($keys as $k => $_) {
        if (isset($_POST[$k])) $save($k, trim($_POST[$k]));
    }
    if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $info = @getimagesize($_FILES['logo']['tmp_name']);
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
        if (!$ext || $_FILES['logo']['size'] > 2 * 1024 * 1024) {
            flash('اللوجو لازم يكون صورة PNG أو JPG أقل من 2 ميجا', 'danger');
            redirect('settings.php');
        }
        $dir = __DIR__ . '/assets/uploads';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        foreach (glob("$dir/logo.*") as $old) unlink($old);
        move_uploaded_file($_FILES['logo']['tmp_name'], "$dir/logo.$ext");
        $save('logo_file', "assets/uploads/logo.$ext");
    }
    if (isset($_POST['remove_logo'])) {
        foreach (glob(__DIR__ . '/assets/uploads/logo.*') as $old) unlink($old);
        $save('logo_file', '');
    }
    flash('تم حفظ الإعدادات');
    redirect('settings.php');
}
$lastPkg = q('SELECT id FROM packages ORDER BY id DESC LIMIT 1')->fetchColumn();
$logo = setting('logo_file');
$pageTitle = 'الإعدادات';
require __DIR__ . '/inc/header.php';
?>
<h4>الإعدادات</h4>
<form method="post" enctype="multipart/form-data" class="card p-3" style="max-width:720px">
  <?= csrf_field() ?>
  <?php foreach ($keys as $k => [$label, $type]): ?>
    <div class="mb-2 row"><label class="col-sm-5 col-form-label"><?= $label ?></label>
      <div class="col-sm-7"><input name="<?= $k ?>" type="<?= $type ?>" step="any" class="form-control<?= $type === 'number' ? ' num' : '' ?>" value="<?= e(setting($k)) ?>"></div></div>
  <?php endforeach; ?>
  <div class="mb-2 row"><label class="col-sm-5 col-form-label">لوجو الستيكر</label>
    <div class="col-sm-7">
      <?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?>
        <img src="<?= e($logo) ?>?v=<?= filemtime(__DIR__ . '/' . $logo) ?>" style="max-height:70px" class="mb-1 border p-1 bg-white d-block">
        <label class="small"><input type="checkbox" name="remove_logo"> شيل اللوجو</label>
      <?php endif; ?>
      <input type="file" name="logo" accept="image/png,image/jpeg" class="form-control">
    </div></div>
  <div class="form-text mb-2">ستيكر الطابعة Xprinter XP-428B المتظبط حالياً: 76 × 130 مم (2.99 × 5.12 بوصة).</div>
  <div><button class="btn btn-success">حفظ</button>
    <?php if ($lastPkg): ?><a class="btn btn-outline-secondary" target="_blank" href="label.php?id=<?= (int)$lastPkg ?>">معاينة آخر ستيكر</a><?php endif; ?></div>
</form>
<?php require __DIR__ . '/inc/footer.php';
