<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('store');

$type = $_GET['type'] ?? '';
$asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$hideZero = isset($_GET['nz']);
$sql = "SELECT i.*, COALESCE(SUM(CASE WHEN m.qty>0 THEN m.qty END),0) qty_in, COALESCE(-SUM(CASE WHEN m.qty<0 THEN m.qty END),0) qty_out,
               COALESCE(SUM(m.qty),0) bal, COALESCE(SUM(m.pieces),0) pcs
        FROM items i LEFT JOIN stock_moves m ON m.item_id=i.id AND m.move_date < DATE_ADD(?, INTERVAL 1 DAY)
        WHERE i.active=1";
$params = [$asOf];
if (isset(ITEM_TYPES[$type])) { $sql .= ' AND i.type=?'; $params[] = $type; }
$sql .= ' GROUP BY i.id' . ($hideZero ? ' HAVING ABS(bal) > 0.0005' : '') . ' ORDER BY i.type, i.code';
$rows = q($sql, $params)->fetchAll();

$pageTitle = 'المخزن';
require __DIR__ . '/inc/header.php';
?>
<h4>رصيد المخزن</h4>
<form class="row g-2 mb-2 no-print">
  <div class="col-auto"><select name="type" class="form-select"><option value="">كل الأنواع</option>
    <?php foreach (ITEM_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><input type="date" name="date" class="form-control" value="<?= e($asOf) ?>"></div>
  <div class="col-auto d-flex align-items-center"><input type="checkbox" name="nz" id="nz" class="form-check-input me-1"<?= $hideZero ? ' checked' : '' ?>><label for="nz">إخفاء الصفر</label></div>
  <div class="col-auto"><button class="btn btn-primary">عرض</button> <button type="button" onclick="print()" class="btn btn-outline-secondary">طباعة</button></div>
</form>
<div class="card p-0">
<table class="table table-sm table-hover mb-0">
  <thead class="table-light"><tr><th>الكود</th><th>الصنف</th><th>النوع</th><th>اللوط</th><th>داخل</th><th>خارج</th><th>الرصيد</th><th>العدد</th><th class="no-print"></th></tr></thead>
  <?php $tot = []; foreach ($rows as $r): $tot[$r['unit']] = ($tot[$r['unit']] ?? 0) + $r['bal']; $d = $r['unit'] === 'kg' ? 3 : 0; ?>
    <tr><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><?= ITEM_TYPES[$r['type']] ?></td><td><?= e($r['lot_no']) ?></td>
      <td class="num"><?= fmt_qty($r['qty_in'], $d) ?></td><td class="num"><?= fmt_qty($r['qty_out'], $d) ?></td>
      <td class="num fw-bold <?= $r['bal'] < -0.0005 ? 'neg' : '' ?>"><?= fmt_qty($r['bal'], $d) ?> <?= UNITS[$r['unit']] ?></td>
      <td class="num"><?= $r['unit'] === 'kg' ? (int)$r['pcs'] : '' ?></td>
      <td class="no-print"><a class="btn btn-sm btn-outline-secondary" href="item_card.php?id=<?= $r['id'] ?>">الحركة</a></td></tr>
  <?php endforeach; ?>
  <tfoot class="table-light"><tr><th colspan="6">الإجمالي</th><th colspan="3" class="num"><?= fmt_qty($tot['kg'] ?? 0) ?> كيلو<?= isset($tot['pcs']) ? ' — ' . fmt_qty($tot['pcs'], 0) . ' قطعة' : '' ?></th></tr></tfoot>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
