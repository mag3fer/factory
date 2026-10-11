<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('store');

$item = q('SELECT * FROM items WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$item) { http_response_code(404); exit('غير موجود'); }
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');

$opening = (float)q('SELECT COALESCE(SUM(qty),0) FROM stock_moves WHERE item_id=? AND move_date < ?', [$item['id'], $from])->fetchColumn();
$moves = q("SELECT m.*, d.type doc_type, d.number doc_no, d.voided, p.name party, k.serial
            FROM stock_moves m
            LEFT JOIN docs d ON m.source='doc' AND d.id=m.source_id
            LEFT JOIN parties p ON p.id=d.party_id
            LEFT JOIN packages k ON m.source='package' AND k.id=m.source_id
            WHERE m.item_id=? AND m.move_date >= ? AND m.move_date < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY m.move_date, m.id", [$item['id'], $from, $to])->fetchAll();
$dec = $item['unit'] === 'kg' ? 3 : 0;
$pageTitle = 'حركة ' . $item['code'];
require __DIR__ . '/inc/header.php';
?>
<h4>حركة الصنف: <?= e($item['code'] . ' — ' . $item['name']) ?></h4>
<form class="row g-2 mb-2 no-print">
  <input type="hidden" name="id" value="<?= $item['id'] ?>">
  <div class="col-auto">من <input type="date" name="from" class="form-control d-inline w-auto" value="<?= e($from) ?>"></div>
  <div class="col-auto">إلى <input type="date" name="to" class="form-control d-inline w-auto" value="<?= e($to) ?>"></div>
  <div class="col-auto"><button class="btn btn-primary">عرض</button> <button type="button" onclick="print()" class="btn btn-outline-secondary">طباعة</button></div>
</form>
<div class="card p-0">
<table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>التاريخ</th><th>البيان</th><th>داخل</th><th>خارج</th><th>الرصيد</th><th>العدد</th></tr></thead>
  <tr class="table-secondary"><td><?= e($from) ?></td><td>رصيد أول المدة</td><td></td><td></td><td class="num fw-bold"><?= fmt_qty($opening, $dec) ?></td><td></td></tr>
  <?php $bal = $opening; foreach ($moves as $m): $bal += $m['qty'];
    $desc = $m['source'] === 'doc' ? DOC_TYPES[$m['doc_type']] . ' رقم ' . $m['doc_no'] . ($m['party'] ? ' — ' . $m['party'] : '')
                                   : 'كرتونة ' . $m['serial'] . ($m['qty'] > 0 ? ' (إنتاج)' : ' (تعبئة)');
    $link = $m['source'] === 'doc' ? 'doc_view.php?id=' . $m['source_id'] : 'packages.php?q=' . urlencode((string)$m['serial']); ?>
    <tr><td><?= e(substr($m['move_date'], 0, 16)) ?></td><td><a href="<?= $link ?>"><?= e($desc) ?></a></td>
      <td class="num"><?= $m['qty'] > 0 ? fmt_qty($m['qty'], $dec) : '' ?></td><td class="num"><?= $m['qty'] < 0 ? fmt_qty(-$m['qty'], $dec) : '' ?></td>
      <td class="num <?= $bal < -0.0005 ? 'neg' : '' ?>"><?= fmt_qty($bal, $dec) ?></td><td class="num"><?= $item['unit'] === 'kg' && $m['pieces'] ? (int)$m['pieces'] : '' ?></td></tr>
  <?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
