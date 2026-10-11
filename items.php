<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('store');

$fields = ['code', 'name', 'type', 'unit', 'spec', 'poy_spec', 'lot_no', 'grade', 'color', 'tare_weight', 'notes'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $v = [];
    foreach ($fields as $f) $v[$f] = trim((string)($_POST[$f] ?? ''));
    $v['tare_weight'] = (float)($v['tare_weight'] ?: 0);
    $v['active'] = isset($_POST['active']) ? 1 : 0;
    if ($v['code'] === '' || $v['name'] === '' || !isset(ITEM_TYPES[$v['type']]) || !isset(UNITS[$v['unit']])) {
        flash('الكود والاسم والنوع والوحدة مطلوبين', 'danger');
        redirect('items.php' . ($id ? "?edit=$id" : '?new=1'));
    }
    foreach (['spec', 'poy_spec', 'lot_no', 'grade', 'color', 'notes'] as $f) $v[$f] = $v[$f] === '' ? null : $v[$f];
    try {
        if ($id) {
            q('UPDATE items SET code=?, name=?, type=?, unit=?, spec=?, poy_spec=?, lot_no=?, grade=?, color=?, tare_weight=?, notes=?, active=? WHERE id=?',
                [...array_values($v), $id]);
            flash('تم حفظ الصنف');
        } else {
            q('INSERT INTO items (code, name, type, unit, spec, poy_spec, lot_no, grade, color, tare_weight, notes, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_values($v));
            flash('تم إضافة الصنف');
        }
    } catch (PDOException $ex) {
        flash((int)($ex->errorInfo[1] ?? 0) === 1062 ? 'الكود ده موجود قبل كده' : 'خطأ في الحفظ', 'danger');
        redirect('items.php' . ($id ? "?edit=$id" : '?new=1'));
    }
    redirect('items.php?type=' . urlencode($v['type']));
}

$edit = null;
if (isset($_GET['edit'])) $edit = q('SELECT * FROM items WHERE id=?', [(int)$_GET['edit']])->fetch() ?: null;
$showForm = $edit || isset($_GET['new']);
if (!$edit) $edit = ['id' => 0, 'code' => '', 'name' => '', 'type' => $_GET['type'] ?? 'raw', 'unit' => 'kg', 'spec' => '', 'poy_spec' => '', 'lot_no' => '', 'grade' => '', 'color' => '', 'tare_weight' => 0, 'notes' => '', 'active' => 1];

$type = $_GET['type'] ?? '';
$sql = 'SELECT i.*, COALESCE(SUM(m.qty),0) bal, COALESCE(SUM(m.pieces),0) pcs FROM items i LEFT JOIN stock_moves m ON m.item_id=i.id';
$params = [];
if (isset(ITEM_TYPES[$type])) { $sql .= ' WHERE i.type=?'; $params[] = $type; }
$items = q($sql . ' GROUP BY i.id ORDER BY i.type, i.code', $params)->fetchAll();

$pageTitle = 'الأصناف';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0">الأصناف</h4>
  <a href="items.php?new=1&type=<?= e($type ?: 'raw') ?>" class="btn btn-primary">+ صنف جديد</a>
</div>

<?php if ($showForm): ?>
<div class="card p-3 mb-3">
  <h6><?= $edit['id'] ? 'تعديل صنف' : 'صنف جديد' ?></h6>
  <form method="post" class="row g-2">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="col-md-2"><label class="form-label">الكود</label><input name="code" class="form-control" value="<?= e($edit['code']) ?>" required></div>
    <div class="col-md-4"><label class="form-label">الاسم</label><input name="name" class="form-control" value="<?= e($edit['name']) ?>" required></div>
    <div class="col-md-2"><label class="form-label">النوع</label><select name="type" class="form-select">
      <?php foreach (ITEM_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $edit['type'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">الوحدة</label><select name="unit" class="form-select">
      <?php foreach (UNITS as $k => $l): ?><option value="<?= $k ?>"<?= $edit['unit'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">وزن القطعة (كيلو)</label><input name="tare_weight" type="number" step="0.001" class="form-control num" value="<?= e((string)$edit['tare_weight']) ?>">
      <div class="form-text">للكونات والكراتين (الفوارغ)</div></div>
    <div class="col-md-2"><label class="form-label">النمرة / المواصفة</label><input name="spec" class="form-control" value="<?= e($edit['spec']) ?>" placeholder="150/144"></div>
    <div class="col-md-2"><label class="form-label">مواصفة POY</label><input name="poy_spec" class="form-control" value="<?= e($edit['poy_spec']) ?>" placeholder="250D/144F"></div>
    <div class="col-md-2"><label class="form-label">رقم اللوط</label><input name="lot_no" class="form-control" value="<?= e($edit['lot_no']) ?>"></div>
    <div class="col-md-1"><label class="form-label">الدرجة</label><input name="grade" class="form-control" value="<?= e($edit['grade']) ?>"></div>
    <div class="col-md-1"><label class="form-label">اللون</label><input name="color" class="form-control" value="<?= e($edit['color']) ?>"></div>
    <div class="col-md-3"><label class="form-label">ملاحظات</label><input name="notes" class="form-control" value="<?= e($edit['notes']) ?>"></div>
    <div class="col-md-1 d-flex align-items-end"><div class="form-check"><input type="checkbox" name="active" class="form-check-input" id="act"<?= $edit['active'] ? ' checked' : '' ?>><label for="act" class="form-check-label">نشط</label></div></div>
    <div class="col-12"><button class="btn btn-success">حفظ</button> <a href="items.php" class="btn btn-secondary">إلغاء</a></div>
  </form>
</div>
<?php endif; ?>

<ul class="nav nav-pills mb-2">
  <li class="nav-item"><a class="nav-link<?= $type === '' ? ' active' : '' ?>" href="items.php">الكل</a></li>
  <?php foreach (ITEM_TYPES as $k => $l): ?><li class="nav-item"><a class="nav-link<?= $type === $k ? ' active' : '' ?>" href="items.php?type=<?= $k ?>"><?= $l ?></a></li><?php endforeach; ?>
</ul>
<div class="card p-0">
<table class="table table-hover table-sm mb-0">
  <thead class="table-light"><tr><th>الكود</th><th>الاسم</th><th>النوع</th><th>المواصفة</th><th>POY</th><th>اللوط</th><th>الدرجة</th><th>وزن القطعة</th><th>الرصيد</th><th>العدد</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <tr class="<?= $i['active'] ? '' : 'text-muted' ?>">
      <td><?= e($i['code']) ?></td><td><?= e($i['name']) ?></td><td><?= ITEM_TYPES[$i['type']] ?></td>
      <td><?= e($i['spec']) ?></td><td><?= e($i['poy_spec']) ?></td><td><?= e($i['lot_no']) ?></td><td><?= e($i['grade']) ?></td>
      <td class="num"><?= $i['tare_weight'] > 0 ? fmt_qty($i['tare_weight']) : '' ?></td>
      <td class="num <?= $i['bal'] < 0 ? 'neg' : '' ?>"><?= fmt_qty($i['bal'], $i['unit'] === 'kg' ? 3 : 0) ?> <?= UNITS[$i['unit']] ?></td>
      <td class="num"><?= $i['unit'] === 'kg' ? (int)$i['pcs'] : '' ?></td>
      <td class="text-nowrap"><a href="item_card.php?id=<?= $i['id'] ?>" class="btn btn-sm btn-outline-secondary">الحركة</a> <a href="items.php?edit=<?= $i['id'] ?>" class="btn btn-sm btn-outline-primary">تعديل</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
