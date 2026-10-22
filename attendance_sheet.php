<?php
// Printable attendance sheet (كشف حضور): one row per worker, one column per day, to be filled by hand and sent back. ?from=&to=&all=1
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/hr.php';
require_role('admin');

$ok = fn(string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
$from = $ok('from', date('Y-m-01'));
$to = $ok('to', date('Y-m-t', strtotime($from)));
if ($from > $to) [$from, $to] = [$to, $from];
if ((strtotime($to) - strtotime($from)) / 86400 > 30) $to = date('Y-m-d', strtotime($from . ' +30 days'));   // at most 31 columns
$all = isset($_GET['all']);

$days = [];
for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) $days[] = $t;
$wd = ['ح', 'ن', 'ث', 'ر', 'خ', 'ج', 'س'];   // Sunday .. Saturday
$letters = ['present' => 'ح', 'half' => '½', 'rest' => 'ر', 'absent' => 'غ'];

$workers = q("SELECT * FROM workers WHERE (active=1" . ($all ? '' : " AND pay_type<>'trip'") . ") OR id IN (SELECT worker_id FROM attendance WHERE work_date BETWEEN ? AND ?) ORDER BY FIELD(pay_type,'monthly','daily','trip'), name", [$from, $to])->fetchAll();
$rec = [];
foreach (q('SELECT worker_id, work_date, status FROM attendance WHERE work_date BETWEEN ? AND ? ORDER BY shift', [$from, $to])->fetchAll() as $r) $rec[(int)$r['worker_id']][$r['work_date']] = $r['status'];

$logo = setting('logo_file');
$pageTitle = 'كشف حضور';
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>كشف حضور <?= e($from) ?> — <?= e($to) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
body { background: #fff; color: #000; font-family: "Cairo", Tahoma, sans-serif; margin: 0; }
.bar { padding: .6rem 1rem; background: #f1f5f9; display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; border-bottom: 1px solid #cbd5e1; }
.bar input, .bar button, .bar a { font: inherit; padding: .25rem .6rem; border: 1px solid #94a3b8; border-radius: 6px; background: #fff; color: #000; text-decoration: none; }
.bar button.p { background: #2563eb; color: #fff; border-color: #2563eb; }
.sheet { padding: 8mm; }
.head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3mm; }
.head img { height: 18mm; } .head .co { font-size: 15pt; font-weight: 800; } .head .sm { font-size: 9.5pt; }
.title { text-align: center; margin: 0 0 3mm; } .title span { display: inline-block; background: #d0d3d8; border: 1px solid #333; padding: 1mm 14mm; font-size: 15pt; font-weight: 800; }
.legend { font-size: 9.5pt; margin-bottom: 2mm; text-align: center; } .legend b { border: 1px solid #333; padding: 0 2mm; margin: 0 1mm; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th, td { border: 1px solid #333; text-align: center; font-size: 8.5pt; padding: 0; height: 9.5mm; overflow: hidden; }
th { background: #d0d3d8; height: auto; padding: 1mm 0; font-weight: 800; }
th.d { font-size: 8pt; line-height: 1.1; } th.d small { display: block; font-weight: 600; font-size: 7pt; }
td.n { text-align: right; padding: 0 1.5mm; font-weight: 700; font-size: 9.5pt; } td.j { font-size: 8pt; padding: 0 1mm; }
td.pre { background: repeating-linear-gradient(45deg, #ddd, #ddd 2px, #fff 2px, #fff 5px); }
td.mark { font-weight: 800; font-size: 11pt; }
.foot { display: flex; justify-content: space-between; margin-top: 8mm; padding: 0 12mm; font-size: 10pt; } .foot div { text-align: center; min-width: 55mm; border-top: 1px solid #000; padding-top: 1mm; }
@page { size: A4 landscape; margin: 0; }
@media print { .bar { display: none; } .sheet { padding: 8mm; } th, td.mark, .title span { -webkit-print-color-adjust: exact; print-color-adjust: exact; } td.pre { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style></head><body>
<form class="bar" method="get">
  <b>كشف حضور:</b>
  <label>من <input type="date" name="from" value="<?= e($from) ?>"></label>
  <label>إلى <input type="date" name="to" value="<?= e($to) ?>"></label>
  <label><input type="checkbox" name="all" value="1"<?= $all ? ' checked' : '' ?>> مع السواقين اللي بالمشوار</label>
  <button>عرض</button>
  <button type="button" class="p" onclick="print()">🖨 طباعة</button>
  <a href="attendance_sheet_pdf.php?from=<?= e($from) ?>&to=<?= e($to) ?><?= $all ? '&all=1' : '' ?>">⬇ PDF</a>
  <a href="attendance.php">رجوع للحضور</a>
</form>
<div class="sheet">
  <div class="head">
    <div><div class="co"><?= e(setting('company_name')) ?></div><div class="sm"><?= e(setting('company_address')) ?></div></div>
    <div><?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><img src="assets/brand.svg" alt=""><?php endif; ?></div>
  </div>
  <div class="title"><span>كشف الحضور من <?= e(date('d/m/Y', strtotime($from))) ?> إلى <?= e(date('d/m/Y', strtotime($to))) ?></span></div>
  <div class="legend">اكتب في الخانة: <b>ح</b> حاضر <b>½</b> نص يوم <b>ر</b> راحة <b>غ</b> غايب — والخانة الفاضية = لسه ما اتسجلتش. المخطّط = قبل بداية شغل العامل. وفي «إضافي» اكتب عدد الساعات.</div>
  <table>
    <colgroup><col style="width:6mm"><col style="width:37mm"><col style="width:21mm"><col style="width:13mm">
      <?php foreach ($days as $_): ?><col><?php endforeach; ?>
      <col style="width:9mm"><col style="width:9mm"><col style="width:9mm"><col style="width:11mm"></colgroup>
    <thead><tr><th>#</th><th>الاسم</th><th>الوظيفة</th><th>بداية</th>
      <?php foreach ($days as $t): $w = (int)date('w', $t); ?><th class="d"><?= (int)date('j', $t) ?><small><?= $wd[$w] ?></small></th><?php endforeach; ?>
      <th>حاضر</th><th>غياب</th><th>راحة</th><th>إضافي</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($workers as $w): $i++; $id = (int)$w['id']; $np = 0; ?>
      <tr><td><?= $i ?></td><td class="n"><?= e($w['name']) ?></td><td class="j"><?= e($w['job'] ?: WORKER_ROLES[$w['role']]) ?></td>
        <td class="j"><?= $w['start_date'] ? e(date('j/n', strtotime($w['start_date']))) : '' ?></td>
        <?php foreach ($days as $t): $d = date('Y-m-d', $t); $st = $rec[$id][$d] ?? null; $pre = !empty($w['start_date']) && $d < $w['start_date'];
              $cls = $pre ? 'pre' : ''; if ($st) $cls .= ' mark'; ?>
          <td class="<?= trim($cls) ?>"><?= $st ? $letters[$st] : '' ?></td>
        <?php endforeach; ?>
        <td></td><td></td><td><?= !empty($w['rest_days']) ? '<small>من ' . (int)$w['rest_days'] . '</small>' : '' ?></td><td></td></tr>
    <?php endforeach; ?>
    <?php for ($k = 0; $k < 3; $k++): ?><tr><td></td><td></td><td></td><td></td><?php foreach ($days as $_): ?><td></td><?php endforeach; ?><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
    </tbody>
  </table>
  <div class="foot"><div>المنظم</div><div>المراجع</div><div>المدير</div></div>
</div>
</body></html>
