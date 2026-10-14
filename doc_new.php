<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/stock.php';
$u = require_role('store');

$type = $_GET['type'] ?? $_POST['type'] ?? 'receipt';
if (!isset(DOC_TYPES[$type])) $type = 'receipt';
if ($type === 'opening' && $u['role'] !== 'admin') $type = 'receipt';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $lines = [];
    foreach ((array)($_POST['item_id'] ?? []) as $i => $itemId) {
        if (!$itemId || ($_POST['qty'][$i] ?? '') === '') continue;
        $lines[] = [
            'item_id' => (int)$itemId, 'qty' => (float)$_POST['qty'][$i], 'pieces' => (int)($_POST['pieces'][$i] ?? 0),
            'pallets' => $_POST['pallets'][$i] ?? '', 'price' => $_POST['price'][$i] ?? '', 'notes' => trim($_POST['line_notes'][$i] ?? ''),
        ];
    }
    try {
        $date = $_POST['doc_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('تاريخ غير صحيح');
        $id = post_doc([
            'type' => $type, 'doc_date' => $date, 'party_id' => (int)($_POST['party_id'] ?? 0),
            'ref_no' => trim($_POST['ref_no'] ?? ''), 'notes' => trim($_POST['notes'] ?? ''),
        ], $lines, $u['id']);
        flash('تم حفظ المستند');
        redirect("doc_view.php?id=$id");
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'danger');
        redirect("doc_new.php?type=$type");
    }
}

$allowedTypes = ['receipt' => ['raw', 'cone', 'pack', 'other'], 'issue' => ['raw', 'cone', 'pack', 'other'], 'adjust' => array_keys(ITEM_TYPES), 'opening' => array_keys(ITEM_TYPES)][$type];
$items = items_by_type(...$allowedTypes);
$parties = q("SELECT id, name FROM parties WHERE active=1 AND type='supplier' ORDER BY name")->fetchAll();

$pageTitle = DOC_TYPES[$type];
require __DIR__ . '/inc/header.php';
?>
<h4><?= DOC_TYPES[$type] ?> — مستند جديد</h4>
<?php if ($type === 'adjust' || $type === 'opening'): ?>
  <div class="alert alert-info py-2">الكمية بالموجب = زيادة الرصيد، بالسالب = نقص الرصيد.</div>
<?php elseif ($type === 'issue'): ?>
  <div class="alert alert-info py-2">صرف خام للمكن (أو كونات / كراتين) — بيتخصم من الرصيد.</div>
<?php endif; ?>
<form method="post" class="card p-3" id="docForm">
  <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>">
  <div class="row g-2 mb-3">
    <div class="col-md-2"><label class="form-label">التاريخ</label><input type="date" name="doc_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
    <?php if ($type === 'receipt'): ?>
    <div class="col-md-3"><label class="form-label">المورد</label><select name="party_id" class="form-select"><option value="">—</option>
      <?php foreach ($parties as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">رقم فاتورة / إذن المورد</label><input name="ref_no" class="form-control"></div>
    <?php endif; ?>
    <div class="col-md-5"><label class="form-label">ملاحظات</label><input name="notes" class="form-control"></div>
  </div>
  <table class="table table-sm" id="lines">
    <thead><tr><th style="width:35%">الصنف</th><?php if ($type === 'receipt'): ?><th>عدد البالتات</th><?php endif; ?><th>الكمية</th><th>عدد البكر / الكونات</th><?php if ($type === 'receipt'): ?><th>السعر</th><?php endif; ?><th>ملاحظات</th><th></th></tr></thead>
    <tbody></tbody>
  </table>
  <div class="mb-3"><button type="button" class="btn btn-outline-secondary btn-sm" id="addLine">+ سطر</button>
    <?php if ($type === 'receipt'): $cKg = (float)(setting('container_kg') ?: 25110); $cPal = max(1, (int)(setting('container_pallets') ?: 44)); ?><span class="text-muted small ms-2" id="palletHint" data-kg="<?= $cKg / $cPal ?>">الكمية بتتحسب لوحدها: الحاوية <?= $cPal ?> بالتة = <?= fmt_qty($cKg, 0) ?> كيلو (البالتة <?= fmt_qty($cKg / $cPal, 1) ?> كيلو) — تقدر تعدّلها، والرقم ده بيتغيّر من الإعدادات</span><?php endif; ?></div>
  <div><button class="btn btn-success">حفظ وترحيل</button> <a href="docs.php" class="btn btn-secondary">رجوع</a></div>
</form>
<template id="lineTpl">
  <tr>
    <td><select name="item_id[]" class="form-select form-select-sm"><option value="">— اختر —</option>
      <?php foreach ($items as $i): ?><option value="<?= $i['id'] ?>" data-unit="<?= $i['unit'] ?>"><?= e($i['code'] . ' — ' . $i['name']) ?></option><?php endforeach; ?></select></td>
    <?php if ($type === 'receipt'): ?><td><input name="pallets[]" type="number" min="0" class="form-control form-control-sm num pallets"></td><?php endif; ?>
    <td><input name="qty[]" type="number" step="0.001" class="form-control form-control-sm num qty"></td>
    <td><input name="pieces[]" type="number" step="1" class="form-control form-control-sm num pieces"></td>
    <?php if ($type === 'receipt'): ?><td><input name="price[]" type="number" step="0.0001" class="form-control form-control-sm num"></td><?php endif; ?>
    <td><input name="line_notes[]" class="form-control form-control-sm"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger del">×</button></td>
  </tr>
</template>
<script>
const tbody = document.querySelector('#lines tbody'), tpl = document.getElementById('lineTpl');
function addLine() { tbody.appendChild(tpl.content.cloneNode(true)); }
document.getElementById('addLine').onclick = addLine;
tbody.addEventListener('click', e => { if (e.target.classList.contains('del')) e.target.closest('tr').remove(); });
tbody.addEventListener('input', e => {
  if (!e.target.classList.contains('pallets')) return;
  const tr = e.target.closest('tr'), n = parseInt(e.target.value || '0', 10);
  const perPallet = parseFloat(document.getElementById('palletHint').dataset.kg);
  tr.querySelector('.qty').value = n ? (n * perPallet).toFixed(3) : '';
  tr.querySelector('.pieces').value = n ? n * 36 : '';
});
addLine(); addLine(); addLine();
</script>
<?php require __DIR__ . '/inc/footer.php';
