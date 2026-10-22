<?php
// Dashboard helpers: ?sig=1 returns a short fingerprint of the data (the page polls it and refreshes itself when it changes);
// ?card=<key> returns the detail view shown when a KPI tile is clicked.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
require __DIR__ . '/inc/scale_stats.php';
require_role('store');

if (isset($_GET['sig'])) {
    header('Content-Type: application/json; charset=utf-8');
    $parts = q("SELECT
        (SELECT CONCAT(COALESCE(MAX(id),0), '.', COUNT(*), '.', COALESCE(SUM(status='in_stock'),0)) FROM packages),
        (SELECT CONCAT(COALESCE(MAX(id),0), '.', COALESCE(SUM(voided),0), '.', COALESCE(SUM(total),0)) FROM sales_invoices),
        (SELECT CONCAT(COALESCE(MAX(id),0), '.', COALESCE(SUM(voided),0)) FROM docs),
        (SELECT CONCAT(COALESCE(MAX(id),0), '.', COALESCE(SUM(amount),0), '.', COALESCE(SUM(voided),0)) FROM trips),
        (SELECT CONCAT(COALESCE(MAX(id),0), '.', COALESCE(SUM(voided),0)) FROM payments),
        (SELECT COUNT(*) FROM attendance)")->fetch(PDO::FETCH_NUM);
    echo json_encode(['sig' => md5(implode('|', $parts)), 'at' => date('H:i:s')]);
    exit;
}

$card = (string)($_GET['card'] ?? '');
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$SACK = "pi.name LIKE '%شكار%'";
$avgPal = (float)setting('container_kg', '25110') / max(1, (int)setting('container_pallets', '44'));
$kg = fn($v, int $d = 1) => fmt_qty((float)$v, $d);
$tag = fn(bool $sack, bool $mixed) => ($sack ? '<em class="t-sack">شكارة</em>' : '<em class="t-ctn">كرتونة</em>') . ($mixed ? '<em class="t-mix">متعدد</em>' : '');
$empty = fn(string $t) => '<div class="d-empty"><i class="bi bi-inbox"></i>' . e($t) . '</div>';
$stat = function (array $boxes): string {   // [label => value] row of summary chips
    $h = '<div class="dd-stats">';
    foreach ($boxes as $l => $v) $h .= '<div><b>' . $v . '</b><span>' . e($l) . '</span></div>';
    return $h . '</div>';
};
$title = '';
ob_start();

switch ($card) {
case 'prod_today':
    $title = 'إنتاج النهارده بالتفصيل';
    $t = q("SELECT COUNT(*) n, COALESCE(SUM(p.net),0) net, COALESCE(SUM(p.gross),0) gross, COALESCE(SUM(p.cones_count),0) cones, COALESCE(SUM($SACK),0) sacks, COALESCE(SUM(p.is_mixed),0) mixed
            FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id WHERE p.status<>'void' AND DATE(p.created_at)=?", [$today])->fetch();
    echo $stat(['كجم صافي' => $kg($t['net']), 'كرتونة' => (int)$t['n'] - (int)$t['sacks'], 'شكارة' => (int)$t['sacks'], 'كونة' => (int)$t['cones'], 'متعدد' => (int)$t['mixed']]);
    $rows = q("SELECT i.code, i.name, ($SACK) sack, p.is_mixed mixed, COUNT(*) n, SUM(p.cones_count) cones, SUM(p.gross) gross, SUM(p.net) net, MIN(p.created_at) t0, MAX(p.created_at) t1
               FROM packages p JOIN items i ON i.id=p.finished_item_id LEFT JOIN items pi ON pi.id=p.pack_item_id
               WHERE p.status<>'void' AND DATE(p.created_at)=? GROUP BY i.id, ($SACK), p.is_mixed ORDER BY net DESC", [$today])->fetchAll();
    echo '<h6 class="dd-h">حسب النمرة</h6>';
    if ($rows) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>النمرة</th><th>النوع</th><th>العدد</th><th>كونات</th><th>قائم</th><th>صافي</th><th>متوسط الكونة</th><th>من — لحد</th></tr></thead>';
        foreach ($rows as $r) echo '<tr><td><b>' . e($r['code']) . '</b><small>' . e($r['name']) . '</small></td><td>' . $tag((bool)$r['sack'], (bool)$r['mixed']) . '</td><td>' . (int)$r['n'] . '</td><td>' . (int)$r['cones'] . '</td><td class="num">' . $kg($r['gross']) . '</td><td class="num fw-bold">' . $kg($r['net']) . '</td><td class="num">' . ($r['cones'] > 0 ? number_format($r['gross'] / $r['cones'], 2) : '—') . '</td><td class="num">' . substr($r['t0'], 11, 5) . ' — ' . substr($r['t1'], 11, 5) . '</td></tr>';
        echo '</table>';
    } else echo $empty('لسه مفيش إنتاج النهارده');
    $last = q("SELECT p.serial, p.created_at, p.cones_count, p.gross, p.net, p.is_mixed, ($SACK) sack, p.pallet_seq, pl.serial ps, pl.capacity cap, i.code
               FROM packages p JOIN items i ON i.id=p.finished_item_id LEFT JOIN items pi ON pi.id=p.pack_item_id LEFT JOIN pallets pl ON pl.id=p.pallet_id
               WHERE p.status<>'void' AND DATE(p.created_at)=? ORDER BY p.id DESC LIMIT 15", [$today])->fetchAll();
    if ($last) {
        echo '<h6 class="dd-h">آخر الوزنات</h6><table class="table table-sm dd-t"><thead><tr><th>الوقت</th><th>الباركود</th><th>النمرة</th><th>النوع</th><th>البالتة</th><th>كونات</th><th>قائم</th><th>صافي</th></tr></thead>';
        foreach ($last as $r) echo '<tr><td class="num">' . substr($r['created_at'], 11, 5) . '</td><td class="num">' . e($r['serial']) . '</td><td>' . e($r['code']) . '</td><td>' . $tag((bool)$r['sack'], (bool)$r['is_mixed']) . '</td><td class="num" dir="ltr">' . ($r['ps'] ? e($r['ps']) . ' · ' . (int)$r['pallet_seq'] . '/' . (int)$r['cap'] : '—') . '</td><td>' . (int)$r['cones_count'] . '</td><td class="num">' . $kg($r['gross'], 2) . '</td><td class="num fw-bold">' . $kg($r['net'], 2) . '</td></tr>';
        echo '</table><a class="dd-more" href="packages.php">كل الكراتين ←</a>';
    }
    break;

case 'prod_month':
    $title = 'إنتاج الشهر بالتفصيل';
    $days = q("SELECT DATE(p.created_at) d, COUNT(*) n, SUM($SACK) sacks, SUM(p.is_mixed) mixed, SUM(p.cones_count) cones, SUM(p.net) net
               FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id WHERE p.status<>'void' AND p.created_at >= ? GROUP BY DATE(p.created_at) ORDER BY d DESC", [$monthStart])->fetchAll();
    $tot = ['n' => 0, 's' => 0, 'net' => 0.0];
    foreach ($days as $r) { $tot['n'] += $r['n']; $tot['s'] += $r['sacks']; $tot['net'] += $r['net']; }
    echo $stat(['طن صافي' => number_format($tot['net'] / 1000, 2), 'كرتونة' => $tot['n'] - $tot['s'], 'شكارة' => $tot['s'], 'يوم شغل' => count($days), 'متوسط اليوم (كجم)' => $days ? $kg($tot['net'] / count($days), 0) : 0]);
    $cnt = q("SELECT i.code, i.name, COUNT(*) n, SUM($SACK) sacks, SUM(p.net) net FROM packages p JOIN items i ON i.id=p.finished_item_id LEFT JOIN items pi ON pi.id=p.pack_item_id
              WHERE p.status<>'void' AND p.created_at >= ? GROUP BY i.id ORDER BY net DESC", [$monthStart])->fetchAll();
    echo '<div class="dd-2"><div><h6 class="dd-h">يوم بيوم</h6>';
    if ($days) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>اليوم</th><th>كراتين</th><th>شكاير</th><th>كونات</th><th>صافي (كجم)</th></tr></thead>';
        foreach ($days as $r) echo '<tr><td class="num">' . e(date('d/m', strtotime($r['d']))) . '</td><td>' . ((int)$r['n'] - (int)$r['sacks']) . '</td><td>' . (int)$r['sacks'] . '</td><td>' . (int)$r['cones'] . '</td><td class="num fw-bold">' . $kg($r['net']) . '</td></tr>';
        echo '</table>';
    } else echo $empty('لسه مفيش إنتاج الشهر ده');
    echo '</div><div><h6 class="dd-h">حسب النمرة</h6>';
    if ($cnt) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>النمرة</th><th>كراتين</th><th>شكاير</th><th>صافي (كجم)</th><th>النسبة</th></tr></thead>';
        foreach ($cnt as $r) echo '<tr><td><b>' . e($r['code']) . '</b><small>' . e($r['name']) . '</small></td><td>' . ((int)$r['n'] - (int)$r['sacks']) . '</td><td>' . (int)$r['sacks'] . '</td><td class="num fw-bold">' . $kg($r['net']) . '</td><td class="num">' . ($tot['net'] > 0 ? round($r['net'] / $tot['net'] * 100) : 0) . '%</td></tr>';
        echo '</table>';
    }
    echo '</div></div>';
    break;

case 'sales_month':
case 'sold_month':
    $title = $card === 'sales_month' ? 'مبيعات الشهر بالتفصيل' : 'الكميات المباعة الشهر ده';
    $inv = q("SELECT s.id, s.type, s.kind, s.number, s.inv_date, s.total, p.name, s.no_trip,
                (SELECT COALESCE(SUM(l.qty),0) FROM sales_lines l WHERE l.invoice_id=s.id) qty,
                (SELECT COALESCE(SUM(l.packages_count),0) FROM sales_lines l WHERE l.invoice_id=s.id) pk,
                (SELECT GROUP_CONCAT(DISTINCT i.code SEPARATOR '، ') FROM sales_lines l JOIN items i ON i.id=l.item_id WHERE l.invoice_id=s.id) codes,
                (SELECT COUNT(*) FROM trips t WHERE t.invoice_id=s.id AND t.voided=0) trips
              FROM sales_invoices s JOIN parties p ON p.id=s.customer_id WHERE s.voided=0 AND s.inv_date >= ? ORDER BY s.inv_date DESC, s.id DESC", [$monthStart])->fetchAll();
    $byItem = q("SELECT i.code, i.name, i.type, SUM(IF(s.type='sale', l.qty, -l.qty)) qty, SUM(IF(s.type='sale', l.amount, -l.amount)) amt, SUM(l.packages_count) pk
                 FROM sales_invoices s JOIN sales_lines l ON l.invoice_id=s.id JOIN items i ON i.id=l.item_id WHERE s.voided=0 AND s.inv_date >= ? GROUP BY i.id ORDER BY qty DESC", [$monthStart])->fetchAll();
    $amt = 0.0; $q = 0.0;
    foreach ($inv as $r) { $sg = $r['type'] === 'sale' ? 1 : -1; $amt += $sg * $r['total']; $q += $sg * $r['qty']; }
    echo $stat(['فاتورة' => count($inv), 'كجم' => $kg($q, 0), 'ج.م' => $kg($amt, 0), 'عميل' => count(array_unique(array_column($inv, 'name')))]);
    echo '<h6 class="dd-h">حسب الصنف</h6>';
    if ($byItem) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>الصنف</th><th>النوع</th><th>الكمية (كجم)</th><th>كراتين/شكاير</th><th>القيمة</th></tr></thead>';
        foreach ($byItem as $r) echo '<tr><td><b>' . e($r['code']) . '</b><small>' . e($r['name']) . '</small></td><td>' . e(ITEM_TYPES[$r['type']] ?? $r['type']) . '</td><td class="num fw-bold">' . $kg($r['qty']) . '</td><td>' . ((int)$r['pk'] ?: '—') . '</td><td class="num">' . $kg($r['amt'], 0) . '</td></tr>';
        echo '</table>';
    }
    echo '<h6 class="dd-h">الفواتير</h6>';
    if ($inv) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>رقم</th><th>التاريخ</th><th>العميل</th><th>الأصناف</th><th>كجم</th><th>كراتين</th><th>القيمة</th><th>المشوار</th></tr></thead>';
        foreach ($inv as $r) echo '<tr><td><a href="sale_view.php?id=' . (int)$r['id'] . '">' . ($r['type'] === 'return' ? 'مرتجع ' : '') . (int)$r['number'] . '</a></td><td class="num">' . e(date('d/m', strtotime($r['inv_date']))) . '</td><td>' . e($r['name']) . '</td><td class="small">' . e((string)$r['codes']) . '</td><td class="num fw-bold">' . $kg($r['qty']) . '</td><td>' . ((int)$r['pk'] ?: '—') . '</td><td class="num">' . ((float)$r['total'] > 0 ? $kg($r['total'], 0) : '<span class="text-muted">بدون سعر</span>') . '</td><td>' . ($r['trips'] ? '<span class="tag ok">اتسجل</span>' : ($r['no_trip'] ? '<span class="tag">ملهاش</span>' : '<span class="tag warn">لسه</span>')) . '</td></tr>';
        echo '</table><a class="dd-more" href="sales.php">كل الفواتير ←</a>';
    } else echo $empty('لسه مفيش فواتير الشهر ده');
    break;

case 'receivable':
    $title = 'المستحق على العملاء';
    $rows = [];
    foreach (q("SELECT p.id, p.name, p.phone, (SELECT MAX(s.inv_date) FROM sales_invoices s WHERE s.customer_id=p.id AND s.voided=0) last_inv,
                  (SELECT MAX(y.pay_date) FROM payments y WHERE y.party_id=p.id AND y.voided=0) last_pay
                FROM parties p WHERE p.type='customer' AND p.active=1")->fetchAll() as $p) {
        $b = party_balance((int)$p['id']);
        if (abs($b) > 0.005) $rows[] = $p + ['bal' => $b];
    }
    usort($rows, fn($a, $b) => $b['bal'] <=> $a['bal']);
    $pos = array_sum(array_map(fn($r) => max(0, $r['bal']), $rows));
    $col = (float)q("SELECT COALESCE(SUM(amount),0) FROM payments WHERE voided=0 AND type='receipt' AND pay_date >= ?", [$monthStart])->fetchColumn();
    echo $stat(['ج.م مستحق' => $kg($pos, 0), 'عميل عليه فلوس' => count(array_filter($rows, fn($r) => $r['bal'] > 0)), 'اتحصّل الشهر ده' => $kg($col, 0)]);
    if ($rows) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>العميل</th><th>التليفون</th><th>آخر فاتورة</th><th>آخر دفعة</th><th>الرصيد</th><th></th></tr></thead>';
        foreach ($rows as $r) echo '<tr><td><b>' . e($r['name']) . '</b></td><td class="num">' . e((string)$r['phone']) . '</td><td class="num">' . e((string)$r['last_inv']) . '</td><td class="num">' . e((string)$r['last_pay']) . '</td><td class="num fw-bold ' . ($r['bal'] > 0 ? 'text-danger' : 'text-success') . '">' . $kg($r['bal'], 0) . '</td><td><a href="party_statement.php?id=' . (int)$r['id'] . '">كشف حساب</a></td></tr>';
        echo '</table>';
    } else echo $empty('مفيش أرصدة مستحقة — يا إما الفواتير لسه من غير أسعار، يا إما كله دافع');
    $pays = q("SELECT y.pay_date, y.amount, y.method, p.name FROM payments y JOIN parties p ON p.id=y.party_id WHERE y.voided=0 AND y.type='receipt' AND y.pay_date >= ? ORDER BY y.pay_date DESC LIMIT 10", [$monthStart])->fetchAll();
    if ($pays) {
        echo '<h6 class="dd-h">آخر التحصيلات</h6><table class="table table-sm dd-t"><thead><tr><th>التاريخ</th><th>العميل</th><th>الطريقة</th><th>المبلغ</th></tr></thead>';
        foreach ($pays as $r) echo '<tr><td class="num">' . e($r['pay_date']) . '</td><td>' . e($r['name']) . '</td><td>' . e((string)$r['method']) . '</td><td class="num fw-bold">' . $kg($r['amount'], 0) . '</td></tr>';
        echo '</table>';
    }
    break;

case 'stock':
    $title = 'الخيط التام في المخزن';
    $wh = warehouse_stock();
    echo $stat(['كجم' => $kg($wh['total']['kg'], 0), 'كرتونة' => (int)$wh['total']['cartons'], 'شكارة' => (int)$wh['total']['sacks'], 'بالتة' => (int)$wh['total']['pallets'], 'كونة' => (int)$wh['total']['cones']]);
    if ($wh['items']) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>النمرة</th><th>النوع</th><th>العدد</th><th>كونات</th><th>كجم</th><th>بالتات</th><th>البالتة الجارية</th></tr></thead>';
        foreach ($wh['items'] as $i) echo '<tr><td><b>' . e($i['code']) . '</b><small>' . e($i['name']) . '</small></td><td>' . $tag((bool)$i['sack'], (bool)$i['mixed']) . '</td><td>' . (int)$i['n'] . '</td><td>' . (int)$i['cones'] . '</td><td class="num fw-bold">' . $kg($i['kg']) . '</td><td>' . (int)$i['pallets'] . '</td><td>' . ($i['open'] ? (int)$i['open'] . ' / ' . (int)$i['cap'] : '—') . '</td></tr>';
        echo '</table>';
    } else echo $empty('المخزن فاضي');
    $pals = q("SELECT pl.serial, pl.capacity, pl.full, i.code, COUNT(*) n, SUM(p.net) kg, MAX($SACK) sack, MAX(p.is_mixed) mixed
               FROM pallets pl JOIN packages p ON p.pallet_id=pl.id AND p.status='in_stock' JOIN items i ON i.id=pl.finished_item_id LEFT JOIN items pi ON pi.id=p.pack_item_id
               GROUP BY pl.id ORDER BY pl.id DESC LIMIT 30")->fetchAll();
    if ($pals) {
        echo '<h6 class="dd-h">البالتات اللي في المخزن</h6><div class="dd-pals">';
        foreach ($pals as $p) echo '<div class="' . ($p['full'] ? 'full' : 'open') . '"><b dir="ltr">' . e($p['serial']) . '</b><span>' . e($p['code']) . ' ' . $tag((bool)$p['sack'], (bool)$p['mixed']) . '</span><small>' . (int)$p['n'] . ' / ' . (int)$p['capacity'] . ' · ' . $kg($p['kg'], 0) . ' كجم</small></div>';
        echo '</div><a class="dd-more" href="pallets.php">كل البالتات ←</a>';
    }
    break;

case 'raw':
    $title = 'الخام (POY) — لكل لوط';
    $lots = q("SELECT i.code, i.name, i.lot_no, COALESCE(NULLIF(i.poy_spec,''), i.spec) spec,
                 COALESCE(SUM(CASE WHEN m.source='doc' AND m.qty>0 THEN m.qty END),0) inq,
                 COALESCE(-SUM(CASE WHEN m.source='package' THEN m.qty END),0) used,
                 COALESCE(-SUM(CASE WHEN m.source='sale' THEN m.qty END),0) sold,
                 COALESCE(SUM(m.qty),0) bal, MIN(m.move_date) first_in
               FROM items i JOIN stock_moves m ON m.item_id=i.id WHERE i.type='raw' GROUP BY i.id ORDER BY bal DESC")->fetchAll();
    $sum = ['inq' => 0, 'used' => 0, 'sold' => 0, 'bal' => 0];
    foreach ($lots as $l) foreach ($sum as $k => $_) $sum[$k] += (float)$l[$k];
    echo $stat(['طن مستلم' => number_format($sum['inq'] / 1000, 2), 'طن اتصرف للإنتاج' => number_format($sum['used'] / 1000, 2), 'طن اتباع خام' => number_format($sum['sold'] / 1000, 2), 'طن فاضل' => number_format($sum['bal'] / 1000, 2), 'بالتة فاضلة ≈' => number_format($sum['bal'] / $avgPal, 1)]);
    if ($lots) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>اللوط</th><th>النمرة</th><th>أول استلام</th><th>المستلم</th><th>للإنتاج</th><th>اتباع</th><th>الباقي</th><th>≈ بالتة</th><th></th></tr></thead>';
        foreach ($lots as $l) {
            $done = (float)$l['bal'] < 0.5 && (float)$l['sold'] > 0;
            echo '<tr><td class="num"><b>' . e($l['lot_no']) . '</b><small>' . e($l['code']) . '</small></td><td>' . e($l['spec']) . '</td><td class="num">' . e(substr((string)$l['first_in'], 0, 10)) . '</td><td class="num">' . $kg($l['inq'], 0) . '</td><td class="num">' . $kg($l['used'], 0) . '</td><td class="num">' . $kg($l['sold'], 0) . '</td><td class="num fw-bold">' . ($done ? '<span class="tag ok">اتباع بالكامل</span>' : $kg($l['bal'], 0)) . '</td><td class="num">' . number_format((float)$l['bal'] / $avgPal, 1) . '</td><td><a href="lot_report.php?lot=' . urlencode($l['lot_no']) . '">الكشف</a></td></tr>';
        }
        echo '</table>';
    } else echo $empty('مفيش خام متسجل');
    break;

case 'trips':
    $title = 'النقل الشهر ده';
    $rows = q("SELECT t.trip_date, t.amount, t.notes, t.invoice_id, v.name vname, v.plate, w.name driver, s.number inv_no, c.name customer
               FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN workers w ON w.id=t.driver_id
               LEFT JOIN sales_invoices s ON s.id=t.invoice_id LEFT JOIN parties c ON c.id=s.customer_id
               WHERE t.voided=0 AND t.trip_date >= ? ORDER BY t.trip_date DESC, t.id DESC", [$monthStart])->fetchAll();
    $amt = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
    echo $stat(['مشوار' => count($rows), 'ج.م تكلفة' => $kg($amt, 0), 'لسه من غير تكلفة' => count(array_filter($rows, fn($r) => (float)$r['amount'] <= 0))]);
    if ($rows) {
        echo '<table class="table table-sm dd-t"><thead><tr><th>التاريخ</th><th>السواق</th><th>العربية</th><th>الفاتورة</th><th>العميل</th><th>التكلفة</th><th>ملاحظات</th></tr></thead>';
        foreach ($rows as $r) echo '<tr><td class="num">' . e(date('d/m', strtotime($r['trip_date']))) . '</td><td>' . e((string)$r['driver']) . '</td><td>' . e(trim($r['vname'] . ' ' . $r['plate'])) . '</td><td>' . ($r['inv_no'] ? (int)$r['inv_no'] : '—') . '</td><td>' . e((string)$r['customer']) . '</td><td class="num fw-bold">' . ((float)$r['amount'] > 0 ? $kg($r['amount'], 0) : '<span class="tag warn">لسه</span>') . '</td><td class="small">' . e(mb_strimwidth(str_replace(["\r", "\n"], ' ', (string)$r['notes']), 0, 70, '…')) . '</td></tr>';
        echo '</table><a class="dd-more" href="transport.php">النقل ←</a>';
    } else echo $empty('مفيش مشاوير الشهر ده');
    break;

default:
    http_response_code(404);
    exit;
}
$html = ob_get_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['title' => $title, 'html' => $html], JSON_UNESCAPED_UNICODE);
