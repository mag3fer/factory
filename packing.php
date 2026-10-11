<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $packItem = (int)($_POST['pack_item_id'] ?? 0) ?: null;
    $w = array_map(fn($k) => round((float)($_POST[$k] ?? 0), 3), ['cone_weight', 'pack_weight', 'bag_weight']);
    $active = isset($_POST['active']) ? 1 : 0;
    if ($name === '' || min($w) < 0 || max($w) > 50) { flash('الاسم مطلوب والأوزان لازم تكون أرقام صحيحة بالكيلو', 'danger'); redirect('packing.php'); }
    if ($id) q('UPDATE packing_types SET name=?, pack_item_id=?, cone_weight=?, pack_weight=?, bag_weight=?, active=? WHERE id=?', [$name, $packItem, ...$w, $active, $id]);
    else q('INSERT INTO packing_types (name, pack_item_id, cone_weight, pack_weight, bag_weight, active) VALUES (?,?,?,?,?,?)', [$name, $packItem, ...$w, $active]);
    flash('تم الحفظ — الكراتين اللي اتسجلت قبل كده محتفظة بأوزانها القديمة');
    redirect('packing.php');
}
$edit = isset($_GET['edit']) ? q('SELECT * FROM packing_types WHERE id=?', [(int)$_GET['edit']])->fetch() : null;
$rows = q('SELECT t.*, i.code, i.name pack_name FROM packing_types t LEFT JOIN items i ON i.id=t.pack_item_id ORDER BY t.id')->fetchAll();
$packs = items_by_type('pack');
$pageTitle = 'أنواع التعبئة';
require __DIR__ . '/inc/header.php';
?>
<h4>أنواع التعبئة (أوزان الفوارغ)</h4>
<p class="text-muted">الفوارغ = عدد الكونات × وزن الكونة + وزن الكرتونة/الشكارة + عدد الكونات × وزن الكيس. الأوزان بالكيلو.</p>
<div class="card p-3 mb-3">
  <form method="post" class="row g-2 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="col-md-3"><label class="form-label">الاسم</label><input name="name" class="form-control" required value="<?= e($edit['name'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label">الكرتونة/الشكارة (بتتخصم من المخزن)</label><select name="pack_item_id" class="form-select"><option value="">—</option>
      <?php foreach ($packs as $p): ?><option value="<?= $p['id'] ?>"<?= (int)($edit['pack_item_id'] ?? 0) === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">وزن الكونة</label><input name="cone_weight" type="number" step="0.001" min="0" class="form-control num" required value="<?= e((string)($edit['cone_weight'] ?? '')) ?>"></div>
    <div class="col-md-2"><label class="form-label">وزن الكرتونة/الشكارة</label><input name="pack_weight" type="number" step="0.001" min="0" class="form-control num" required value="<?= e((string)($edit['pack_weight'] ?? '')) ?>"></div>
    <div class="col-md-1"><label class="form-label">وزن الكيس</label><input name="bag_weight" type="number" step="0.001" min="0" class="form-control num" required value="<?= e((string)($edit['bag_weight'] ?? '')) ?>"></div>
    <div class="col-md-1"><div class="form-check"><input type="checkbox" name="active" class="form-check-input" id="ac"<?= ($edit['active'] ?? 1) ? ' checked' : '' ?>><label for="ac" class="form-check-label">نشط</label></div></div>
    <div class="col-12"><button class="btn btn-success"><?= $edit ? 'حفظ التعديل' : '+ إضافة' ?></button><?php if ($edit): ?> <a href="packing.php" class="btn btn-secondary">إلغاء</a><?php endif; ?></div>
  </form>
</div>
<div class="card p-0">
<table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>الاسم</th><th>الكرتونة/الشكارة</th><th>وزن الكونة</th><th>وزن الكرتونة/الشكارة</th><th>وزن الكيس</th><th>مثال: فوارغ 6 كونات</th><th></th></tr></thead>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['active'] ? '' : 'text-muted' ?>"><td><?= e($r['name']) ?></td><td><?= e($r['pack_name']) ?></td>
      <td class="num"><?= fmt_qty($r['cone_weight']) ?></td><td class="num"><?= fmt_qty($r['pack_weight']) ?></td><td class="num"><?= fmt_qty($r['bag_weight']) ?></td>
      <td class="num"><?= fmt_qty(6 * $r['cone_weight'] + $r['pack_weight'] + 6 * $r['bag_weight']) ?></td>
      <td><a class="btn btn-sm btn-outline-primary" href="?edit=<?= $r['id'] ?>">تعديل</a></td></tr>
  <?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
