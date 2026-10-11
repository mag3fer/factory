<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

$keys = [
    'company_name' => ['اسم الشركة على الستيكر', 'text'],
    'company_phone1' => ['رقم الهاتف 1', 'text'],
    'company_phone2' => ['رقم الهاتف 2', 'text'],
    'bag_weight' => ['وزن الكيس لكل كونة (كيلو)', 'number'],
    'default_cones' => ['عدد الكونات الافتراضي في الكرتونة', 'number'],
    'label_width_mm' => ['عرض الستيكر (مم)', 'number'],
    'label_height_mm' => ['طول الستيكر (مم)', 'number'],
    'scale_baud' => ['سرعة بورت الميزان (Baud)', 'number'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($keys as $k => $_) {
        if (isset($_POST[$k])) q('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)', [$k, trim($_POST[$k])]);
    }
    flash('تم حفظ الإعدادات');
    redirect('settings.php');
}
$pageTitle = 'الإعدادات';
require __DIR__ . '/inc/header.php';
?>
<h4>الإعدادات</h4>
<form method="post" class="card p-3" style="max-width:640px">
  <?= csrf_field() ?>
  <?php foreach ($keys as $k => [$label, $type]): ?>
    <div class="mb-2 row"><label class="col-sm-6 col-form-label"><?= $label ?></label>
      <div class="col-sm-6"><input name="<?= $k ?>" type="<?= $type ?>" step="any" class="form-control<?= $type === 'number' ? ' num' : '' ?>" value="<?= e(setting($k)) ?>"></div></div>
  <?php endforeach; ?>
  <div><button class="btn btn-success">حفظ</button></div>
</form>
<?php require __DIR__ . '/inc/footer.php';
