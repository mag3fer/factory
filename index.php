<?php
// Owner dashboard: everything about the factory on one animated page — production now and over time, quality,
// finished stock (cartons / sacks / pallets), raw POY per lot, sales & money, workforce, transport, alerts and latest activity.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
require __DIR__ . '/inc/scale_stats.php';
$u = require_role();
if ($u['role'] === 'scale') redirect('scale.php');

$today = date('Y-m-d');
$yday = date('Y-m-d', strtotime('-1 day'));
$monthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthSameDay = date('Y-m-d', min(strtotime('last day of last month'), strtotime('-1 month')));
$SACK = "pi.name LIKE '%شكار%'";
$pct = fn(float $now, float $before) => $before > 0.0005 ? round(($now - $before) / $before * 100) : null;

// ---------- production ----------
$prodDay = fn(string $d) => q("SELECT COUNT(*) n, COALESCE(SUM(p.net),0) net, COALESCE(SUM($SACK),0) sacks, COALESCE(SUM(p.cones_count),0) cones, COALESCE(SUM(p.is_mixed),0) mixed
                              FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id WHERE p.status<>'void' AND DATE(p.created_at)=?", [$d])->fetch();
$pToday = $prodDay($today);
$pYday = $prodDay($yday);
$pMonth = q("SELECT COUNT(*) n, COALESCE(SUM(p.net),0) net, COALESCE(SUM($SACK),0) sacks FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id
             WHERE p.status<>'void' AND p.created_at >= ?", [$monthStart])->fetch();
$pLastMonth = (float)q("SELECT COALESCE(SUM(net),0) FROM packages WHERE status<>'void' AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)", [$lastMonthStart, $lastMonthSameDay])->fetchColumn();
$lastPkg = q("SELECT p.created_at, p.serial, i.code FROM packages p JOIN items i ON i.id=p.finished_item_id WHERE p.status<>'void' ORDER BY p.id DESC LIMIT 1")->fetch();
$minsSince = $lastPkg ? (int)floor((time() - strtotime($lastPkg['created_at'])) / 60) : null;

$hours = array_fill(0, 24, 0.0);
foreach (q("SELECT HOUR(created_at) h, SUM(net) kg FROM packages WHERE status<>'void' AND DATE(created_at)=? GROUP BY HOUR(created_at)", [$today])->fetchAll() as $r) $hours[(int)$r['h']] = round((float)$r['kg'], 1);

// last 30 days: produced kg, finished kg sold, sales amount
$days = [];
for ($i = 29; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = ['prod' => 0, 'sold' => 0, 'amt' => 0];
$d0 = array_key_first($days);
foreach (q("SELECT DATE(created_at) d, SUM(net) kg FROM packages WHERE status<>'void' AND created_at >= ? GROUP BY DATE(created_at)", [$d0])->fetchAll() as $r) $days[$r['d']]['prod'] = round((float)$r['kg'], 1);
foreach (q("SELECT s.inv_date d, SUM(IF(s.type='sale', l.qty, -l.qty)) kg FROM sales_invoices s JOIN sales_lines l ON l.invoice_id=s.id JOIN items i ON i.id=l.item_id
            WHERE s.voided=0 AND i.type='finished' AND s.inv_date >= ? GROUP BY s.inv_date", [$d0])->fetchAll() as $r) if (isset($days[$r['d']])) $days[$r['d']]['sold'] = round((float)$r['kg'], 1);
foreach (q("SELECT inv_date d, SUM(IF(type='sale', total, -total)) amt FROM sales_invoices WHERE voided=0 AND inv_date >= ? GROUP BY inv_date", [$d0])->fetchAll() as $r) if (isset($days[$r['d']])) $days[$r['d']]['amt'] = round((float)$r['amt']);
$last14 = array_slice($days, -14, 14, true);

$byCount = q("SELECT i.code, COALESCE(NULLIF(i.spec,''), i.code) spec, SUM(p.net) kg, COUNT(*) n FROM packages p JOIN items i ON i.id=p.finished_item_id
              WHERE p.status<>'void' AND p.created_at >= ? GROUP BY i.id ORDER BY kg DESC LIMIT 8", [$monthStart])->fetchAll();

// ---------- quality (standard only) ----------
$T = (float)setting('target_gross_per_cone', '6');
$okP = (float)setting('target_ok_pct', '3');
$warnP = (float)setting('target_warn_pct', '6');
$qual = q("SELECT COUNT(*) n, COALESCE(SUM(gross),0) g, COALESCE(SUM(cones_count),0) c,
             COALESCE(SUM(ABS(gross - cones_count*?) / (cones_count*?) * 100 <= ?),0) ok,
             COALESCE(SUM(ABS(gross - cones_count*?) / (cones_count*?) * 100 > ? AND ABS(gross - cones_count*?) / (cones_count*?) * 100 <= ?),0) warn
           FROM packages WHERE status<>'void' AND is_mixed=0 AND cones_count>0 AND created_at >= ?", [$T, $T, $okP, $T, $T, $okP, $T, $T, $warnP, $monthStart])->fetch();
$qN = (int)$qual['n'];
$qAvg = $qual['c'] > 0 ? (float)$qual['g'] / (float)$qual['c'] : 0;
$qOkPct = $qN ? round($qual['ok'] / $qN * 100) : 0;
$qWarnPct = $qN ? round($qual['warn'] / $qN * 100) : 0;
$qBadPct = $qN ? max(0, 100 - $qOkPct - $qWarnPct) : 0;

// ---------- stock ----------
$wh = warehouse_stock();
$pallStock = (int)q("SELECT COUNT(DISTINCT pallet_id) FROM packages WHERE status='in_stock' AND pallet_id IS NOT NULL")->fetchColumn();
$openPal = (int)q("SELECT COUNT(*) FROM pallets WHERE full=0 AND closed=0 AND EXISTS (SELECT 1 FROM packages p WHERE p.pallet_id=pallets.id AND p.status='in_stock')")->fetchColumn();
$avgPal = (float)setting('container_kg', '25110') / max(1, (int)setting('container_pallets', '44'));
$lots = q("SELECT i.id, i.code, i.name, i.lot_no, COALESCE(NULLIF(i.poy_spec,''), i.spec) spec, i.active,
             COALESCE(SUM(CASE WHEN m.source='doc' AND m.qty>0 THEN m.qty END),0) inq,
             COALESCE(-SUM(CASE WHEN m.source='package' THEN m.qty END),0) used,
             COALESCE(-SUM(CASE WHEN m.source='sale' THEN m.qty END),0) sold,
             COALESCE(SUM(m.qty),0) bal
           FROM items i JOIN stock_moves m ON m.item_id=i.id WHERE i.type='raw' GROUP BY i.id ORDER BY bal DESC, i.code")->fetchAll();
$rawTotal = array_sum(array_map(fn($r) => (float)$r['bal'], $lots));
$supplies = q("SELECT i.name, i.type, COALESCE(SUM(m.qty),0) qty FROM items i LEFT JOIN stock_moves m ON m.item_id=i.id
               WHERE i.active=1 AND i.type IN ('cone','pack') GROUP BY i.id ORDER BY i.type, qty")->fetchAll();

// ---------- sales & money ----------
$salesAmt = fn(string $a, string $b) => (float)q("SELECT COALESCE(SUM(IF(type='sale', total, -total)),0) FROM sales_invoices WHERE voided=0 AND inv_date BETWEEN ? AND ?", [$a, $b])->fetchColumn();
$sMonth = $salesAmt($monthStart, $today);
$sLast = $salesAmt($lastMonthStart, $lastMonthSameDay);
$sToday = $salesAmt($today, $today);
$sCount = (int)q("SELECT COUNT(*) FROM sales_invoices WHERE voided=0 AND type='sale' AND inv_date >= ?", [$monthStart])->fetchColumn();
$soldKg = q("SELECT COALESCE(SUM(IF(i.type='finished', IF(s.type='sale', l.qty, -l.qty), 0)),0) fin, COALESCE(SUM(IF(i.type='raw', IF(s.type='sale', l.qty, -l.qty), 0)),0) raw
             FROM sales_invoices s JOIN sales_lines l ON l.invoice_id=s.id JOIN items i ON i.id=l.item_id WHERE s.voided=0 AND s.inv_date >= ?", [$monthStart])->fetch();
$collected = (float)q("SELECT COALESCE(SUM(amount),0) FROM payments WHERE voided=0 AND type='receipt' AND pay_date >= ?", [$monthStart])->fetchColumn();
$topCust = q("SELECT p.name, SUM(IF(s.type='sale', s.total, -s.total)) amt, COUNT(*) n,
                SUM((SELECT COALESCE(SUM(IF(s.type='sale', l.qty, -l.qty)),0) FROM sales_lines l WHERE l.invoice_id=s.id)) kg
              FROM sales_invoices s JOIN parties p ON p.id=s.customer_id
              WHERE s.voided=0 AND s.inv_date >= ? GROUP BY p.id ORDER BY amt DESC, kg DESC LIMIT 5", [$monthStart])->fetchAll();
$debtors = [];
foreach (q("SELECT id, name FROM parties WHERE type='customer' AND active=1")->fetchAll() as $p) {
    $b = party_balance((int)$p['id']);
    if ($b > 0.005) $debtors[] = ['name' => $p['name'], 'bal' => $b];
}
usort($debtors, fn($a, $b) => $b['bal'] <=> $a['bal']);
$receivable = array_sum(array_column($debtors, 'bal'));
$recentSales = q("SELECT s.id, s.type, s.number, s.inv_date, s.total, s.created_at, p.name, (SELECT COALESCE(SUM(l.qty),0) FROM sales_lines l WHERE l.invoice_id=s.id) kg FROM sales_invoices s JOIN parties p ON p.id=s.customer_id WHERE s.voided=0 ORDER BY s.id DESC LIMIT 6")->fetchAll();

// ---------- people & transport ----------
$workersN = (int)q("SELECT COUNT(*) FROM workers WHERE active=1")->fetchColumn();
$attDate = q("SELECT MAX(work_date) FROM attendance")->fetchColumn();
$att = $attDate ? q("SELECT COALESCE(SUM(status='present'),0) present, COALESCE(SUM(status='half'),0) half, COALESCE(SUM(status='absent'),0) absent, COALESCE(SUM(status='rest'),0) rest
                     FROM attendance WHERE work_date=?", [$attDate])->fetch() : ['present' => 0, 'half' => 0, 'absent' => 0, 'rest' => 0];
$attPct = $workersN ? round(((int)$att['present'] + 0.5 * (int)$att['half']) / $workersN * 100) : 0;
$trips = q("SELECT COUNT(*) n, COALESCE(SUM(amount),0) amt, COALESCE(SUM(amount=0),0) pending FROM trips WHERE voided=0 AND trip_date >= ?", [$monthStart])->fetch();
$noTrip = (int)q("SELECT COUNT(*) FROM sales_invoices s WHERE s.voided=0 AND s.type='sale' AND s.no_trip=0 AND s.inv_date >= ? AND NOT EXISTS (SELECT 1 FROM trips t WHERE t.invoice_id=s.id AND t.voided=0)", [$monthStart])->fetchColumn();

// ---------- alerts ----------
$alerts = [];
foreach ($supplies as $s) if ((float)$s['qty'] < 200) $alerts[] = ['danger', 'exclamation-octagon', ($s['type'] === 'cone' ? 'كونات' : 'عبوات') . ' قربت تخلص: ' . $s['name'] . ' — فاضل ' . fmt_qty((float)$s['qty'], 0)];
foreach ($lots as $l) if ((float)$l['bal'] > 0.5 && (float)$l['inq'] > 0 && (float)$l['bal'] / (float)$l['inq'] < 0.1) $alerts[] = ['warning', 'layers', 'خام لوط ' . $l['lot_no'] . ' قرب يخلص — فاضل ' . fmt_qty((float)$l['bal'], 0) . ' كجم'];
if ($trips['pending'] > 0) $alerts[] = ['info', 'truck', $trips['pending'] . ' مشوار لسه تكلفته ما اتكتبتش'];
if ($noTrip > 0) $alerts[] = ['info', 'signpost-split', $noTrip . ' فاتورة الشهر ده لسه ما اتحددلهاش مشوار'];
if ($minsSince !== null && $minsSince > 120 && (int)date('G') >= 8 && (int)date('G') < 22) $alerts[] = ['warning', 'speedometer', 'الميزان ما سجّلش من ' . ($minsSince >= 1440 ? floor($minsSince / 1440) . ' يوم' : floor($minsSince / 60) . ' ساعة')];

// ---------- activity ----------
$feed = [];
foreach ($recentSales as $s) $feed[] = ['t' => $s['created_at'], 'ic' => 'receipt', 'c' => 'green', 'txt' => ($s['type'] === 'sale' ? 'فاتورة بيع ' : 'مرتجع ') . (int)$s['number'] . ' — ' . $s['name'], 'v' => (float)$s['total'] > 0 ? fmt_qty((float)$s['total'], 0) . ' ج' : fmt_qty((float)$s['kg'], 0) . ' كجم', 'href' => 'sale_view.php?id=' . (int)$s['id']];
foreach (q("SELECT d.id, d.type, d.number, d.created_at, p.name FROM docs d LEFT JOIN parties p ON p.id=d.party_id WHERE d.voided=0 ORDER BY d.id DESC LIMIT 4")->fetchAll() as $d)
    $feed[] = ['t' => $d['created_at'], 'ic' => 'truck', 'c' => 'blue', 'txt' => DOC_TYPES[$d['type']] . ' رقم ' . $d['number'] . ($d['name'] ? ' — ' . $d['name'] : ''), 'v' => '', 'href' => 'doc_view.php?id=' . (int)$d['id']];
foreach (q("SELECT pl.serial, pl.capacity, MAX(p.created_at) t, i.code, MAX(pi.name LIKE '%شكار%') sack FROM pallets pl JOIN packages p ON p.pallet_id=pl.id JOIN items i ON i.id=pl.finished_item_id
            LEFT JOIN items pi ON pi.id=p.pack_item_id WHERE pl.full=1 GROUP BY pl.id ORDER BY pl.id DESC LIMIT 4")->fetchAll() as $p)
    $feed[] = ['t' => $p['t'], 'ic' => 'boxes', 'c' => 'cyan', 'txt' => 'اكتملت بالتة ' . $p['serial'] . ' — ' . $p['code'], 'v' => $p['capacity'] . ' ' . ($p['sack'] ? 'شكارة' : 'كرتونة'), 'href' => 'pallets.php'];
usort($feed, fn($a, $b) => strcmp((string)$b['t'], (string)$a['t']));
$feed = array_slice($feed, 0, 9);
$ago = function (?string $t): string {
    if (!$t) return '';
    $m = (int)floor((time() - strtotime($t)) / 60);
    if ($m < 1) return 'دلوقتي';
    if ($m < 60) return "من $m دقيقة";
    if ($m < 1440) return 'من ' . floor($m / 60) . ' ساعة';
    return 'من ' . floor($m / 1440) . ' يوم';
};

$hour = (int)date('G');
$greet = $hour < 12 ? 'صباح الخير' : ($hour < 18 ? 'مساء النور' : 'مساء الخير');
$logoFull = setting('logo_full');
$pageTitle = 'لوحة التحكم';
$pageScripts = ['assets/chart.umd.min.js'];
require __DIR__ . '/inc/header.php';

$delta = function (?int $p, string $vs): string {
    if ($p === null) return '<span class="dl flat">— ' . e($vs) . '</span>';
    $cls = $p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat');
    $ar = $p > 0 ? '▲' : ($p < 0 ? '▼' : '•');
    return '<span class="dl ' . $cls . '">' . $ar . ' ' . abs($p) . '% <small>' . e($vs) . '</small></span>';
};
$ring = function (float $p, string $color, string $label, string $sub = '') {
    $p = max(0, min(100, $p)); $c = 2 * M_PI * 42;
    return '<div class="ring"><svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="42" class="trk"/><circle cx="50" cy="50" r="42" class="bar" stroke="' . $color . '" stroke-dasharray="' . round($c, 2) . '" stroke-dashoffset="' . round($c, 2) . '" data-off="' . round($c * (1 - $p / 100), 2) . '"/></svg>'
        . '<div class="ring-in"><b dir="ltr"><span data-count="' . round($p) . '">0</span>%</b><span>' . e($label) . '</span></div>' . ($sub ? '<div class="ring-sub">' . $sub . '</div>' : '') . '</div>';
};
?>
<link rel="stylesheet" href="assets/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/dashboard.css') ?>">
<div class="dash">

  <!-- ===== hero ===== -->
  <section class="d-hero reveal">
    <div class="d-hero-bg"><i></i><i></i><i></i></div>
    <div class="d-hero-main">
      <div class="d-hero-id">
        <?php if ($logoFull && is_file(__DIR__ . '/' . $logoFull)): ?><img src="<?= e($logoFull) ?>" alt="" class="d-logo"><?php endif; ?>
        <div>
          <div class="d-greet"><?= $greet ?>، <?= e($u['full_name']) ?> 👋</div>
          <h2><?= e(setting('company_name', 'المصنع')) ?></h2>
          <div class="d-sub"><?= e(setting('company_address')) ?></div>
        </div>
      </div>
      <div class="d-hero-clock"><div id="dClock" class="num">--:--:--</div><div id="dDate"></div></div>
    </div>
    <div class="d-hero-pills">
      <span class="pill <?= $minsSince !== null && $minsSince <= 30 ? 'live' : 'idle' ?>"><i class="dot"></i>
        <?= $lastPkg ? 'آخر وزنة ' . e($ago($lastPkg['created_at'])) . ' — ' . e($lastPkg['code']) : 'لسه مفيش وزن' ?></span>
      <span class="pill"><i class="bi bi-speedometer"></i> النهارده <b class="num"><?= fmt_qty((float)$pToday['net'], 0) ?></b> كجم — <?= e(count_text((int)$pToday['n'] - (int)$pToday['sacks'], (int)$pToday['sacks'])) ?></span>
      <span class="pill"><i class="bi bi-cash-stack"></i> مبيعات النهارده <b class="num"><?= fmt_qty($sToday, 0) ?></b> ج</span>
      <span class="pill"><i class="bi bi-people"></i> الحضور <b class="num"><?= (int)$att['present'] ?></b> من <?= $workersN ?></span>
    </div>
  </section>

  <!-- ===== KPIs ===== -->
  <section class="d-kpis">
    <div class="dk reveal c-cyan has-spark"><div class="dk-top"><span class="dk-ic"><i class="bi bi-speedometer2"></i></span><span class="dk-lbl">إنتاج النهارده</span></div>
      <div class="dk-val"><span data-count="<?= round((float)$pToday['net'], 1) ?>" data-dec="1">0</span><small>كجم</small></div>
      <div class="dk-foot"><?= $delta($pct((float)$pToday['net'], (float)$pYday['net']), 'عن امبارح') ?><span><?= (int)$pToday['cones'] ?> كونة</span></div>
      <canvas class="spark" data-spark='<?= json_encode(array_values(array_column($last14, 'prod'))) ?>' data-color="#22d3ee"></canvas></div>
    <div class="dk reveal c-blue has-spark"><div class="dk-top"><span class="dk-ic"><i class="bi bi-calendar3"></i></span><span class="dk-lbl">إنتاج الشهر</span></div>
      <div class="dk-val"><span data-count="<?= round((float)$pMonth['net'] / 1000, 2) ?>" data-dec="2">0</span><small>طن</small></div>
      <div class="dk-foot"><?= $delta($pct((float)$pMonth['net'], $pLastMonth), 'عن نفس الفترة') ?><span><?= e(count_text((int)$pMonth['n'] - (int)$pMonth['sacks'], (int)$pMonth['sacks'])) ?></span></div>
      <canvas class="spark" data-spark='<?= json_encode(array_values(array_column($days, 'prod'))) ?>' data-color="#60a5fa"></canvas></div>
    <div class="dk reveal c-green has-spark"><div class="dk-top"><span class="dk-ic"><i class="bi bi-graph-up-arrow"></i></span><span class="dk-lbl">مبيعات الشهر</span></div>
      <div class="dk-val"><span data-count="<?= round($sMonth) ?>">0</span><small>ج.م</small></div>
      <div class="dk-foot"><?= $delta($pct($sMonth, $sLast), 'عن نفس الفترة') ?><span><?= $sCount ?> فاتورة</span></div>
      <canvas class="spark" data-spark='<?= json_encode(array_values(array_column($days, 'amt'))) ?>' data-color="#34d399"></canvas></div>
    <div class="dk reveal c-red"><div class="dk-top"><span class="dk-ic"><i class="bi bi-wallet2"></i></span><span class="dk-lbl">مستحق على العملاء</span></div>
      <div class="dk-val"><span data-count="<?= round($receivable) ?>">0</span><small>ج.م</small></div>
      <div class="dk-foot"><span><?= count($debtors) ?> عميل عليهم فلوس</span><a href="balances.php">الأرصدة ←</a></div>
      <div class="dk-bar"><i style="--w:<?= $sMonth > 0 ? min(100, round($collected / max(1, $sMonth) * 100)) : 0 ?>%"></i></div><div class="dk-note">اتحصّل الشهر ده <?= fmt_qty($collected, 0) ?> ج</div></div>
    <div class="dk reveal c-violet"><div class="dk-top"><span class="dk-ic"><i class="bi bi-box-seam"></i></span><span class="dk-lbl">خيط تام في المخزن</span></div>
      <div class="dk-val"><span data-count="<?= round((float)$wh['total']['kg'] / 1000, 2) ?>" data-dec="2">0</span><small>طن</small></div>
      <div class="dk-foot"><span><?= e(count_text((int)$wh['total']['cartons'], (int)$wh['total']['sacks'])) ?></span><span><?= $pallStock ?> بالتة</span></div></div>
    <div class="dk reveal c-amber"><div class="dk-top"><span class="dk-ic"><i class="bi bi-layers"></i></span><span class="dk-lbl">رصيد الخام (POY)</span></div>
      <div class="dk-val"><span data-count="<?= round($rawTotal / 1000, 2) ?>" data-dec="2">0</span><small>طن</small></div>
      <div class="dk-foot"><span>≈ <?= number_format($rawTotal / $avgPal, 1) ?> بالتة</span><span><?= count(array_filter($lots, fn($l) => (float)$l['bal'] > 0.5)) ?> لوط شغال</span></div></div>
    <div class="dk reveal c-teal"><div class="dk-top"><span class="dk-ic"><i class="bi bi-bag-check"></i></span><span class="dk-lbl">اتباع الشهر (كجم)</span></div>
      <div class="dk-val"><span data-count="<?= round(((float)$soldKg['fin'] + (float)$soldKg['raw']) / 1000, 2) ?>" data-dec="2">0</span><small>طن</small></div>
      <div class="dk-foot"><span>خيط <?= fmt_qty((float)$soldKg['fin'], 0) ?></span><span>خام <?= fmt_qty((float)$soldKg['raw'], 0) ?></span></div></div>
    <div class="dk reveal c-slate"><div class="dk-top"><span class="dk-ic"><i class="bi bi-truck"></i></span><span class="dk-lbl">النقل الشهر ده</span></div>
      <div class="dk-val"><span data-count="<?= (int)$trips['n'] ?>">0</span><small>مشوار</small></div>
      <div class="dk-foot"><span>تكلفة <?= fmt_qty((float)$trips['amt'], 0) ?> ج</span><a href="transport.php">النقل ←</a></div></div>
  </section>

  <!-- ===== production charts ===== -->
  <section class="d-grid g-8-4">
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-activity"></i> الإنتاج والمبيعات — آخر 30 يوم</h6><span class="dc-tag">كجم</span></div>
      <div class="ch-lg"><canvas id="chDays"></canvas></div></div>
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-clock-history"></i> إنتاج النهارده ساعة بساعة</h6></div>
      <div class="ch-lg"><canvas id="chHours"></canvas></div></div>
  </section>

  <!-- ===== quality / mix / people ===== -->
  <section class="d-grid g-4-4-4">
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-bullseye"></i> الجودة — نظامي الشهر ده</h6><a href="packages.php" class="dc-link">التفاصيل</a></div>
      <div class="q-wrap">
        <?= $ring($qOkPct, '#10b981', 'في المعدل', '<span class="tag ok">±' . fmt_qty($okP, 0) . '%</span>') ?>
        <div class="q-side">
          <div class="q-big"><span class="num"><?= number_format($qAvg, 2) ?></span><small>كجم قائم / كونة</small></div>
          <div class="q-tgt">الهدف <?= fmt_qty($T, 2) ?> — <?= $qAvg > 0 ? ($qAvg >= $T ? '<b class="text-success">+' . number_format(($qAvg - $T) / $T * 100, 1) . '%</b>' : '<b class="text-danger">' . number_format(($qAvg - $T) / $T * 100, 1) . '%</b>') : '—' ?></div>
          <div class="q-stack"><i class="ok" style="--w:<?= $qOkPct ?>%"></i><i class="warn" style="--w:<?= $qWarnPct ?>%"></i><i class="bad" style="--w:<?= $qBadPct ?>%"></i></div>
          <div class="q-leg"><span><i class="ok"></i>مظبوط <?= $qOkPct ?>%</span><span><i class="warn"></i>قريب <?= $qWarnPct ?>%</span><span><i class="bad"></i>بعيد <?= $qBadPct ?>%</span></div>
          <div class="small text-muted"><?= $qN ?> كرتونة/شكارة نظامي</div>
        </div>
      </div></div>
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-pie-chart"></i> إنتاج الشهر حسب النمرة</h6></div>
      <?php if ($byCount): ?><div class="ch-md"><canvas id="chCounts"></canvas></div><?php else: ?><div class="d-empty"><i class="bi bi-inbox"></i>لسه مفيش إنتاج الشهر ده</div><?php endif; ?></div>
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-people"></i> العمالة</h6><a href="attendance.php" class="dc-link">الحضور</a></div>
      <div class="q-wrap">
        <?= $ring($attPct, '#6366f1', 'حضور', $attDate ? '<span class="tag">' . e(date('d/m', strtotime($attDate))) . '</span>' : '') ?>
        <div class="q-side">
          <div class="att-grid">
            <div class="ok"><b><?= (int)$att['present'] ?></b>حاضر</div><div class="warn"><b><?= (int)$att['half'] ?></b>نص يوم</div>
            <div class="bad"><b><?= (int)$att['absent'] ?></b>غايب</div><div class="mut"><b><?= (int)$att['rest'] ?></b>راحة</div>
          </div>
          <div class="small text-muted mt-2"><?= $workersN ?> عامل نشط</div>
        </div>
      </div></div>
  </section>

  <!-- ===== stock ===== -->
  <section class="d-grid g-7-5">
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-boxes"></i> الخيط التام في المخزن</h6><a href="stock.php?type=finished" class="dc-link">جرد المخزن</a></div>
      <div class="st-sum"><div><b class="num"><?= fmt_qty((float)$wh['total']['kg'], 0) ?></b>كجم</div><div><b><?= (int)$wh['total']['cartons'] ?></b>كرتونة</div><div><b><?= (int)$wh['total']['sacks'] ?></b>شكارة</div><div><b><?= $pallStock ?></b>بالتة</div><div><b><?= $openPal ?></b>بالتة بتتملا</div></div>
      <?php $mx = max(1, ...array_map(fn($i) => (float)$i['kg'], $wh['items'] ?: [['kg' => 1]])); foreach (array_slice($wh['items'], 0, 7) as $i): ?>
        <div class="st-row">
          <div class="st-name"><b><?= e($i['code']) ?></b><?= $i['sack'] ? '<em class="t-sack">شكاير</em>' : '' ?><?= $i['mixed'] ? '<em class="t-mix">متعدد</em>' : '' ?><span><?= e($i['name']) ?></span></div>
          <div class="st-bar"><i style="--w:<?= max(3, round((float)$i['kg'] / $mx * 100)) ?>%"></i></div>
          <div class="st-num"><b class="num"><?= fmt_qty((float)$i['kg'], 0) ?></b> كجم<small><?= (int)$i['n'] ?> <?= $i['sack'] ? 'شكارة' : 'كرتونة' ?> · <?= (int)$i['pallets'] ?> بالتة</small></div>
        </div>
      <?php endforeach; if (!$wh['items']): ?><div class="d-empty"><i class="bi bi-inbox"></i>المخزن فاضي</div><?php endif; ?></div>
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-layers"></i> الخام — لكل لوط</h6><a href="stock_lot.php" class="dc-link">جرد اللوطات</a></div>
      <?php foreach ($lots as $l): $in = (float)$l['inq']; $bal = (float)$l['bal']; $p = $in > 0 ? max(0, min(100, $bal / $in * 100)) : 0; $done = $bal < 0.5 && (float)$l['sold'] > 0; ?>
        <a class="lot <?= $done ? 'done' : '' ?>" href="lot_report.php?lot=<?= urlencode($l['lot_no']) ?>">
          <div class="lot-h"><b><?= e($l['spec']) ?></b><span class="num"><?= e($l['lot_no']) ?></span><?= $done ? '<em>✔ اتباع بالكامل</em>' : '' ?></div>
          <div class="lot-bar"><i class="<?= $p < 15 ? 'lo' : ($p < 40 ? 'mid' : 'hi') ?>" style="--w:<?= round($p) ?>%"></i></div>
          <div class="lot-f"><span>فاضل <b class="num"><?= fmt_qty($bal, 0) ?></b> كجم <?= $bal > 0.5 ? '(≈ ' . number_format($bal / $avgPal, 1) . ' بالتة)' : '' ?></span><span>إنتاج <?= fmt_qty((float)$l['used'], 0) ?> · بيع <?= fmt_qty((float)$l['sold'], 0) ?></span></div>
        </a>
      <?php endforeach; if (!$lots): ?><div class="d-empty"><i class="bi bi-inbox"></i>مفيش خام متسجل</div><?php endif; ?>
      <?php if ($supplies): ?><div class="sup"><?php foreach ($supplies as $s): ?><span class="<?= (float)$s['qty'] < 200 ? 'low' : '' ?>"><?= e($s['name']) ?> <b class="num"><?= fmt_qty((float)$s['qty'], 0) ?></b></span><?php endforeach; ?></div><?php endif; ?>
    </div>
  </section>

  <!-- ===== sales / customers / alerts / activity ===== -->
  <section class="d-grid g-4-4-4">
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-trophy"></i> أكبر العملاء الشهر ده</h6><a href="sales.php" class="dc-link">الفواتير</a></div>
      <?php $byKg = $topCust && max(array_map(fn($c) => (float)$c['amt'], $topCust)) <= 0;   // invoices without prices yet: rank by kilos
      $cv = fn($c) => $byKg ? (float)$c['kg'] : (float)$c['amt'];
      $mc = max(1, ...array_map($cv, $topCust ?: [['amt' => 1, 'kg' => 1]])); foreach ($topCust as $k => $c): ?>
        <div class="cu"><span class="cu-rank r<?= $k + 1 ?>"><?= $k + 1 ?></span><div class="cu-main"><div class="cu-h"><b><?= e($c['name']) ?></b><span class="num"><?= $byKg ? fmt_qty((float)$c['kg'], 0) . ' كجم' : fmt_qty((float)$c['amt'], 0) . ' ج' ?></span></div>
          <div class="cu-bar"><i style="--w:<?= max(4, round($cv($c) / $mc * 100)) ?>%"></i></div><small><?= (int)$c['n'] ?> فاتورة · <?= fmt_qty((float)$c['kg'], 0) ?> كجم</small></div></div>
      <?php endforeach; if (!$topCust): ?><div class="d-empty"><i class="bi bi-receipt"></i>لسه مفيش مبيعات الشهر ده</div><?php endif; ?>
      <?php if ($debtors): ?><div class="dc-sub">عليهم فلوس</div>
        <?php foreach (array_slice($debtors, 0, 4) as $d): ?><div class="debt"><span><?= e($d['name']) ?></span><b class="num"><?= fmt_qty($d['bal'], 0) ?> ج</b></div><?php endforeach; endif; ?>
    </div>
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-bell"></i> تنبيهات</h6><span class="dc-tag <?= $alerts ? 'hot' : '' ?>"><?= count($alerts) ?></span></div>
      <?php foreach ($alerts as [$lvl, $ic, $txt]): ?><div class="al <?= $lvl ?>"><i class="bi bi-<?= $ic ?>"></i><span><?= e($txt) ?></span></div><?php endforeach; ?>
      <?php if (!$alerts): ?><div class="d-ok"><i class="bi bi-shield-check"></i><b>كله تمام</b><span>مفيش حاجة محتاجة انتباهك دلوقتي</span></div><?php endif; ?>
      <div class="dc-sub">اختصارات</div>
      <div class="qa">
        <a href="sale_new.php"><i class="bi bi-upc-scan"></i>فاتورة بيع</a><a href="payments.php"><i class="bi bi-cash-coin"></i>سند قبض</a>
        <a href="doc_new.php?type=receipt"><i class="bi bi-truck"></i>استلام خام</a><a href="stock_lot.php"><i class="bi bi-diagram-3"></i>جرد اللوط</a>
        <a href="pallets.php"><i class="bi bi-boxes"></i>البالتات</a><a href="packages.php"><i class="bi bi-box-seam"></i>الكراتين</a>
      </div></div>
    <div class="dc reveal"><div class="dc-h"><h6><i class="bi bi-lightning-charge"></i> آخر الأحداث</h6></div>
      <div class="feed"><?php foreach ($feed as $f): ?>
        <a class="fd" href="<?= e($f['href']) ?>"><span class="fd-ic <?= $f['c'] ?>"><i class="bi bi-<?= $f['ic'] ?>"></i></span><div class="fd-m"><span><?= e($f['txt']) ?></span><small><?= e($ago($f['t'])) ?></small></div><?= $f['v'] ? '<b class="num">' . e($f['v']) . '</b>' : '' ?></a>
      <?php endforeach; if (!$feed): ?><div class="d-empty"><i class="bi bi-clock"></i>لسه مفيش أحداث</div><?php endif; ?></div></div>
  </section>
</div>

<script>
(() => {
  // live clock in the hero
  const ck = document.getElementById('dClock'), dt = document.getElementById('dDate');
  const tick = () => { const n = new Date(); ck.textContent = n.toLocaleTimeString('en-GB'); dt.textContent = n.toLocaleDateString('ar-EG', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); };
  tick(); setInterval(tick, 1000);
  // animate rings and bars once the page is in
  requestAnimationFrame(() => setTimeout(() => {
    document.querySelectorAll('.ring .bar').forEach(c => c.style.strokeDashoffset = c.dataset.off);
    document.querySelectorAll('.dash [style*="--w"]').forEach(el => el.classList.add('go'));
  }, 250));
})();
window.addEventListener('load', () => {
  Chart.defaults.font.family = 'Cairo, Tahoma, sans-serif';
  Chart.defaults.color = '#64748b';
  const grad = (ctx, c1, c2) => { const g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.height); g.addColorStop(0, c1); g.addColorStop(1, c2); return g; };

  document.querySelectorAll('canvas.spark').forEach(cv => {
    const data = JSON.parse(cv.dataset.spark || '[]'), col = cv.dataset.color;
    new Chart(cv, { type: 'line', data: { labels: data.map((_, i) => i), datasets: [{ data, borderColor: col, borderWidth: 2, tension: .4, pointRadius: 0, fill: true, backgroundColor: grad(cv.getContext('2d'), col + '55', col + '00') }] },
      options: { maintainAspectRatio: false, animation: { duration: 1400 }, plugins: { legend: { display: false }, tooltip: { enabled: false } }, scales: { x: { display: false }, y: { display: false, beginAtZero: true } } } });
  });

  const days = <?= json_encode(array_map(fn($d) => date('d/m', strtotime($d)), array_keys($days))) ?>;
  const cd = document.getElementById('chDays'), x = cd.getContext('2d');
  new Chart(cd, { type: 'line',
    data: { labels: days, datasets: [
      { label: 'الإنتاج', data: <?= json_encode(array_values(array_column($days, 'prod'))) ?>, borderColor: '#22b8d8', backgroundColor: grad(x, 'rgba(34,184,216,.35)', 'rgba(34,184,216,0)'), fill: true, tension: .35, borderWidth: 3, pointRadius: 0, pointHoverRadius: 5 },
      { label: 'مبيعات خيط تام', data: <?= json_encode(array_values(array_column($days, 'sold'))) ?>, borderColor: '#10b981', backgroundColor: grad(x, 'rgba(16,185,129,.25)', 'rgba(16,185,129,0)'), fill: true, tension: .35, borderWidth: 3, pointRadius: 0, pointHoverRadius: 5 },
    ] },
    options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, animation: { duration: 1600, easing: 'easeOutQuart' },
      plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } }, scales: { y: { beginAtZero: true, grid: { color: '#eef2f7' } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } } } } });

  const ch = document.getElementById('chHours');
  const hrs = <?= json_encode(array_values($hours)) ?>, nowH = new Date().getHours();
  new Chart(ch, { type: 'bar',
    data: { labels: hrs.map((_, h) => String(h).padStart(2, '0')), datasets: [{ label: 'كجم', data: hrs, borderRadius: 6,
      backgroundColor: hrs.map((_, h) => h === nowH ? '#f59e0b' : 'rgba(37,99,235,.75)') }] },
    options: { maintainAspectRatio: false, animation: { duration: 1300, delay: c => c.dataIndex * 35 }, plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, grid: { color: '#eef2f7' } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } } } } });

  const cc = document.getElementById('chCounts');
  if (cc) new Chart(cc, { type: 'doughnut',
    data: { labels: <?= json_encode(array_map(fn($r) => $r['spec'] . ' — ' . $r['code'], $byCount), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map(fn($r) => round((float)$r['kg'], 1), $byCount)) ?>, backgroundColor: ['#22b8d8', '#2563eb', '#10b981', '#f59e0b', '#a78bfa', '#ef4444', '#14b8a6', '#64748b'], borderWidth: 3, borderColor: '#fff', hoverOffset: 10 }] },
    options: { maintainAspectRatio: false, cutout: '66%', animation: { animateRotate: true, animateScale: true, duration: 1500 }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } } } });
});
</script>
<?php require __DIR__ . '/inc/footer.php';
