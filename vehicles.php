<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hr.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'rates') {
        foreach (rated_trip_kinds() as $k => $_) q('UPDATE trip_rates SET price=? WHERE code=?', [round(max(0, (float)($_POST['price'][$k] ?? 0)), 2), $k]);
        flash('تم حفظ أسعار المشاوير');
        redirect('vehicles.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { flash('اكتب اسم المركبة', 'danger'); redirect('vehicles.php'); }
    $kind = ($_POST['kind'] ?? '') === 'microbus' ? 'microbus' : 'truck';
    $vals = [$name, trim($_POST['plate'] ?? '') ?: null, $kind, (int)($_POST['driver_id'] ?? 0) ?: null, isset($_POST['active']) ? 1 : 0];
    if ($id) q('UPDATE vehicles SET name=?, plate=?, kind=?, driver_id=?, active=? WHERE id=?', [...$vals, $id]);
    else q('INSERT INTO vehicles (name, plate, kind, driver_id, active) VALUES (?,?,?,?,?)', $vals);
    flash('تم حفظ المركبة');
    redirect('vehicles.php');
}

$edit = isset($_GET['edit']) ? q('SELECT * FROM vehicles WHERE id=?', [(int)$_GET['edit']])->fetch() : null;
$vf = $edit ?: ['id' => 0, 'name' => '', 'plate' => '', 'kind' => 'truck', 'driver_id' => null, 'active' => 1];
$drivers = q("SELECT id, name, role FROM workers WHERE active=1 AND role IN ('goods_driver','bus_driver') ORDER BY name")->fetchAll();
$vehicles = q('SELECT v.*, w.name driver FROM vehicles v LEFT JOIN workers w ON w.id=v.driver_id ORDER BY v.active DESC, v.name')->fetchAll();
$rates = q('SELECT * FROM trip_rates')->fetchAll(PDO::FETCH_UNIQUE);
$pageTitle = 'المركبات وأسعار المشاوير';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head"><h4><i class="bi bi-truck"></i> المركبات وأسعار المشاوير</h4></div>
<div class="row g-3">
  <div class="col-xl-5">
    <form method="post" class="card p-3 mb-3">
      <h6 class="fw-bold mb-3"><i class="bi bi-cash-coin"></i> أسعار المشاوير</h6>
      <?= csrf_field() ?><input type="hidden" name="action" value="rates">
      <?php foreach (rated_trip_kinds() as $k => $l): ?>
        <div class="row g-2 mb-2 align-items-center"><div class="col-8"><label class="form-label small mb-0"><?= e($l) ?></label></div>
          <div class="col-4"><input type="number" step="0.01" min="0" name="price[<?= $k ?>]" class="form-control num" value="<?= e((string)($rates[$k]['price'] ?? 0)) ?>"></div></div>
      <?php endforeach; ?>
      <button class="btn btn-success mt-2"><i class="bi bi-check2"></i> حفظ الأسعار</button>
      <div class="form-text mt-2">السعر بيتحط لوحده وقت تسجيل المشوار وتقدر تعدّله لمشوار معيّن. الأسعار القديمة ما بتتغيرش.</div>
    </form>
    <form method="post" class="card p-3">
      <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle"></i> <?= $vf['id'] ? 'تعديل مركبة' : 'مركبة جديدة' ?></h6>
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$vf['id'] ?>">
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">الاسم</label><input name="name" class="form-control" required maxlength="80" value="<?= e($vf['name']) ?>" placeholder="عربية نقل 1 / ميكروباص"></div>
        <div class="col-6"><label class="form-label small">رقم اللوحة</label><input name="plate" class="form-control" maxlength="40" value="<?= e($vf['plate'] ?? '') ?>"></div></div>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">النوع</label><select name="kind" class="form-select"><option value="truck"<?= $vf['kind'] === 'truck' ? ' selected' : '' ?>>عربية نقل بضاعة</option><option value="microbus"<?= $vf['kind'] === 'microbus' ? ' selected' : '' ?>>ميكروباص عمال</option></select></div>
        <div class="col-6"><label class="form-label small">السواق</label><select name="driver_id" class="form-select"><option value="">—</option><?php foreach ($drivers as $d): ?><option value="<?= $d['id'] ?>"<?= (int)$vf['driver_id'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div></div>
      <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active"<?= $vf['active'] ? ' checked' : '' ?>> شغّالة</label>
      <div class="d-flex gap-2"><button class="btn btn-success"><i class="bi bi-check2"></i> حفظ</button><?php if ($vf['id']): ?><a class="btn btn-secondary" href="vehicles.php">جديدة</a><?php endif; ?></div>
      <div class="form-text mt-2">السواق لازم يكون مضاف الأول في «العاملين والسواقين» بوظيفة سائق.</div>
    </form>
  </div>
  <div class="col-xl-7">
    <div class="card p-0 table-wrap"><table class="table table-sm mb-0"><thead><tr><th>المركبة</th><th>النوع</th><th>اللوحة</th><th>السواق</th><th></th></tr></thead>
      <?php foreach ($vehicles as $v): ?><tr class="<?= $v['active'] ? '' : 'text-muted' ?>"><td class="fw-bold"><?= e($v['name']) ?></td><td><?= $v['kind'] === 'microbus' ? 'ميكروباص عمال' : 'نقل بضاعة' ?></td><td class="num"><?= e($v['plate'] ?? '') ?></td><td><?= e($v['driver'] ?? '—') ?></td>
        <td><a class="btn btn-sm btn-outline-secondary" href="vehicles.php?edit=<?= $v['id'] ?>">تعديل</a></td></tr>
      <?php endforeach; if (!$vehicles): ?><tr><td colspan="5" class="text-center text-muted py-3">مفيش مركبات لسه</td></tr><?php endif; ?></table></div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
