<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
require_role('store');

$item = q('SELECT * FROM items WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$item) { http_response_code(404); exit('غير موجود'); }
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');

$opening = (float)q('SELECT COALESCE(SUM(qty),0) FROM stock_moves WHERE item_id=? AND move_date < ?', [$item['id'], $from])->fetchColumn();
$moves = q("SELECT m.*, d.type doc_type, d.number doc_no, d.voided, COALESCE(p.name, sc.name) party, k.serial, (pk.name LIKE '%شكار%') is_sack, si.number sale_no, si.type sale_type
            FROM stock_moves m
            LEFT JOIN docs d ON m.source='doc' AND d.id=m.source_id
            LEFT JOIN parties p ON p.id=d.party_id
            LEFT JOIN packages k ON m.source='package' AND k.id=m.source_id
            LEFT JOIN items pk ON pk.id=k.pack_item_id
            LEFT JOIN sales_invoices si ON m.source='sale' AND si.id=m.source_id
            LEFT JOIN parties sc ON sc.id=si.customer_id
            WHERE m.item_id=? AND m.move_date >= ? AND m.move_date < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY m.move_date, m.id", [$item['id'], $from, $to])->fetchAll();
$dec = $item['unit'] === 'kg' ? 3 : 0;
// Raw POY comes on pallets: a standard pallet (36 cones) and a bigger one (42 cones); a container is 44 pallets
// (38 standard + 6 big = 25,110 kg). Express a weight as pallets + loose cones so a sale or issue can be checked against the label counts.
$isRaw = $item['type'] === 'raw' && $item['unit'] === 'kg';
$palContainer = (int)setting('raw_container_pallets', '44');
$palText = fn(float $kg, bool $receipt) => pallet_text($kg, $receipt);
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
<?php if ($isRaw):
    $tot = ['in' => 0.0, 'sold' => 0.0, 'prod' => 0.0, 'other' => 0.0];
    foreach ($moves as $m) {
        $q = (float)$m['qty'];
        if ($m['source'] === 'sale') $tot['sold'] += -$q;
        elseif ($m['source'] === 'package') $tot['prod'] += -$q;
        elseif ($q > 0) $tot['in'] += $q;
        else $tot['other'] += -$q;
    }
    $closing = $opening + $tot['in'] - $tot['sold'] - $tot['prod'] - $tot['other'];
    $avgPal = (float)setting('raw_container_kg', '25110') / max(1, $palContainer);   // average pallet (38 × 558 + 6 × 651) / 44
    $pc = fn(float $kg) => '≈ ' . number_format($kg / $avgPal, 1) . ' بالتة';
    $boxes = ['رصيد أول المدة' => $opening, 'استلام' => $tot['in'], 'اتباع خام' => $tot['sold'], 'اتصرف للإنتاج (شكاير وكراتين)' => $tot['prod'], 'الرصيد دلوقتي (حسب النظام)' => $closing];
?>
<div class="d-flex flex-wrap gap-2 mb-2">
  <?php foreach ($boxes as $lbl => $v): ?>
    <div class="card px-3 py-2 text-center"><div class="small text-muted"><?= e($lbl) ?></div><b class="num"><?= fmt_qty($v, 3) ?></b><div class="small text-muted"><?= $pc($v) ?></div></div>
  <?php endforeach; ?>
</div>
<div class="small text-muted mb-2">عدد البالتات تقريبي بمتوسط <?= fmt_qty($avgPal, 1) ?> كجم للبالتة. الرصيد حسب النظام = المستلم − المباع − اللي اتصرف للإنتاج؛ لو عدّيت اللي فاضل فعلاً في المخزن واختلف عنه، الفرق هو الهالك.</div>
<?php endif; ?>
<div class="card p-0">
<table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>التاريخ</th><th>البيان</th><th>داخل</th><th>خارج</th><th>الرصيد</th><th>العدد</th></tr></thead>
  <tr class="table-secondary"><td><?= e($from) ?></td><td>رصيد أول المدة</td><td></td><td></td><td class="num fw-bold"><?= fmt_qty($opening, $dec) ?></td><td></td></tr>
  <?php $bal = $opening; foreach ($moves as $m): $bal += $m['qty'];
    $desc = $m['source'] === 'doc' ? DOC_TYPES[$m['doc_type']] . ' رقم ' . $m['doc_no'] . ($m['party'] ? ' — ' . $m['party'] : '')
                                   : ($m['source'] === 'sale' ? ($m['sale_type'] === 'return' ? 'مرتجع بيع رقم ' : 'فاتورة بيع رقم ') . $m['sale_no'] . ($m['party'] ? ' — ' . $m['party'] : '')
                                   : ($m['is_sack'] ? 'شكارة ' : 'كرتونة ') . $m['serial'] . ($m['qty'] > 0 ? ' (إنتاج)' : ' (تعبئة)'));
    $link = $m['source'] === 'doc' ? 'doc_view.php?id=' . $m['source_id'] : ($m['source'] === 'sale' ? 'sale_view.php?id=' . $m['source_id'] : 'packages.php?q=' . urlencode((string)$m['serial']));
    $pt = $isRaw ? $palText((float)$m['qty'], true) : ''; ?>
    <tr><td><?= e(substr($m['move_date'], 0, 16)) ?></td><td><a href="<?= $link ?>"><?= e($desc) ?></a><?= $pt ? '<div class="small text-muted">' . e($pt) . '</div>' : '' ?></td>
      <td class="num"><?= $m['qty'] > 0 ? fmt_qty($m['qty'], $dec) : '' ?></td><td class="num"><?= $m['qty'] < 0 ? fmt_qty(-$m['qty'], $dec) : '' ?></td>
      <td class="num <?= $bal < -0.0005 ? 'neg' : '' ?>"><?= fmt_qty($bal, $dec) ?></td><td class="num"><?= $item['unit'] === 'kg' && $m['pieces'] ? (int)$m['pieces'] : '' ?></td></tr>
  <?php endforeach; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
