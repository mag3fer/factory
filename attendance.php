<?php
// Daily attendance: one record per worker per day (no shifts). Stored with shift='morning' as a fixed value so the table stays unique per day.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hr.php';
$u = require_role('admin');

const DAY_SHIFT = 'morning';
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['date'] ?? '') ? $_REQUEST['date'] : date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'quick_worker') {
        $name = trim($_POST['name'] ?? '');
        $rate = round(max(0, (float)($_POST['rate'] ?? 0)), 2);
        if ($name === '' || $rate <= 0) { flash('اكتب اسم العامل واليومية', 'danger'); redirect("attendance.php?date=$date"); }
        q('INSERT INTO workers (name, role, job, pay_type, rate) VALUES (?,?,?,?,?)', [$name, 'worker', mb_substr(trim($_POST['job'] ?? ''), 0, 80) ?: null, 'daily', $rate]);
        $wid = (int)db()->lastInsertId();
        q('INSERT INTO attendance (worker_id, work_date, shift, status, user_id) VALUES (?,?,?,?,?)', [$wid, $date, DAY_SHIFT, 'present', $u['id']]);
        flash("تم إضافة $name (يومية) وتسجيله حاضر يوم $date");
        redirect("attendance.php?date=$date");
    }
    $st = (array)($_POST['st'] ?? []);
    $ot = (array)($_POST['ot'] ?? []);
    $amts = (array)($_POST['amt'] ?? []);
    $n = 0;
    foreach (q('SELECT id FROM workers')->fetchAll(PDO::FETCH_COLUMN) as $wid) {
        $s = $st[$wid] ?? '';
        q('DELETE FROM attendance WHERE worker_id=? AND work_date=?', [$wid, $date]);      // one record per day
        if (!isset(ATT_STATUS[$s])) continue;
        $hrs = round(max(0, min(24, (float)($ot[$wid] ?? 0))), 2);
        $amt = ($amts[$wid] ?? '') === '' ? null : round(max(0, (float)$amts[$wid]), 2);
        q('INSERT INTO attendance (worker_id, work_date, shift, status, overtime_hours, day_amount, user_id) VALUES (?,?,?,?,?,?,?)', [$wid, $date, DAY_SHIFT, $s, $hrs, $amt, $u['id']]);
        $n++;
    }
    flash("تم حفظ حضور يوم $date ($n)");
    redirect("attendance.php?date=$date");
}

$rec = [];
foreach (q('SELECT * FROM attendance WHERE work_date=? ORDER BY id', [$date])->fetchAll() as $r) $rec[(int)$r['worker_id']] ??= $r;
$workers = q("SELECT * FROM workers WHERE (active=1 AND (role='worker' OR pay_type<>'trip')) OR id IN (SELECT worker_id FROM attendance WHERE work_date=?) ORDER BY FIELD(pay_type,'monthly','daily','trip'), name", [$date])->fetchAll();
$counts = ['present' => 0, 'half' => 0, 'rest' => 0, 'absent' => 0];
foreach ($workers as $w) { $s = $rec[(int)$w['id']]['status'] ?? null; if ($s) $counts[$s]++; }
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));
$pageTitle = 'الحضور';
require __DIR__ . '/inc/header.php';
?>
<style>
tr.att-present > td { background: #dcfce7 !important; } tr.att-half > td { background: #fef3c7 !important; }
tr.att-rest > td { background: #dbeafe !important; } tr.att-absent > td { background: #fee2e2 !important; }
tr.att-none > td { background: #f1f5f9 !important; }
select.st.s-present { border-color: #16a34a; color: #166534; font-weight: 700; } select.st.s-half { border-color: #d97706; color: #92400e; font-weight: 700; }
select.st.s-rest { border-color: #2563eb; color: #1e40af; font-weight: 700; } select.st.s-absent { border-color: #dc2626; color: #991b1b; font-weight: 700; }
.lg { display: inline-block; padding: .1rem .6rem; border-radius: 999px; font-size: .8rem; font-weight: 700; margin-left: .3rem; }
</style>
<div class="page-head"><h4><i class="bi bi-calendar-check"></i> تسجيل الحضور</h4><a class="btn btn-outline-dark" href="attendance_sheet.php?from=<?= e(date('Y-m-01', strtotime($date))) ?>&to=<?= e(date('Y-m-t', strtotime($date))) ?>" target="_blank"><i class="bi bi-printer"></i> طباعة كشف حضور فاضي</a></div>
<form class="card p-3 mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">التاريخ</label><input type="date" name="date" class="form-control" value="<?= e($date) ?>" onchange="this.form.submit()"></div>
    <div class="col-md-5 d-flex gap-2"><a class="btn btn-outline-secondary" href="attendance.php?date=<?= $prev ?>"><i class="bi bi-chevron-right"></i> اليوم اللي قبله</a>
      <a class="btn btn-outline-secondary" href="attendance.php?date=<?= date('Y-m-d') ?>">النهارده</a>
      <a class="btn btn-outline-secondary" href="attendance.php?date=<?= $next ?>">اليوم اللي بعده <i class="bi bi-chevron-left"></i></a></div>
  </div>
  <div class="form-text mt-2">اختار اليوم وعلّم اللي جه. تقدر تسجل أي يوم فات.</div>
</form>
<div class="row g-3">
  <div class="col-xl-8">
    <form method="post" class="card p-0 table-wrap">
      <?= csrf_field() ?><input type="hidden" name="date" value="<?= e($date) ?>">
      <div class="p-3 pb-2 card-title-row"><h6>حضور يوم <?= e($date) ?></h6>
        <span id="sum"><span class="lg" style="background:#dcfce7;color:#166534">حاضر <b id="n-present">0</b></span><span class="lg" style="background:#fef3c7;color:#92400e">نص يوم <b id="n-half">0</b></span><span class="lg" style="background:#dbeafe;color:#1e40af">راحة <b id="n-rest">0</b></span><span class="lg" style="background:#fee2e2;color:#991b1b">غايب <b id="n-absent">0</b></span><span class="lg" style="background:#e2e8f0;color:#334155">لسه <b id="n-none">0</b></span></span></div>
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>العامل</th><th>الوظيفة</th><th>الحالة</th><th style="width:100px">إضافي (ساعات)</th><th style="width:120px" title="اختياري: لو اليوم ده بمبلغ مختلف عن اليومية">مبلغ اليوم</th></tr></thead>
        <?php foreach ($workers as $w): $r = $rec[(int)$w['id']] ?? null; ?>
          <tr class="att-<?= e($r['status'] ?? 'none') ?>"><td class="fw-bold"><?= e($w['name']) ?></td>
            <td class="small"><?= e($w['job'] ?: WORKER_ROLES[$w['role']]) ?> <span class="text-muted">· <?= e(PAY_BASIS[$w['pay_type']]) ?></span></td>
            <td><select name="st[<?= (int)$w['id'] ?>]" class="form-select form-select-sm st s-<?= e($r['status'] ?? '') ?>"><option value="">—</option>
              <?php foreach (ATT_STATUS as $k => $l): ?><option value="<?= $k ?>"<?= ($r['status'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
            <td><input type="number" step="0.5" min="0" max="24" name="ot[<?= (int)$w['id'] ?>]" class="form-control form-control-sm num" value="<?= $r && (float)$r['overtime_hours'] > 0 ? (float)$r['overtime_hours'] : '' ?>"></td>
            <td><input type="number" step="0.01" min="0" name="amt[<?= (int)$w['id'] ?>]" class="form-control form-control-sm num" placeholder="<?= $w['pay_type'] === 'daily' ? e((string)(float)$w['rate']) : '' ?>" value="<?= $r && $r['day_amount'] !== null ? (float)$r['day_amount'] : '' ?>"></td></tr>
        <?php endforeach; if (!$workers): ?><tr><td colspan="5" class="text-center text-muted py-3">مفيش عمال — ضيفهم من «العاملين والسواقين» أو من الفورم اللي جنب</td></tr><?php endif; ?>
      </table>
      <div class="p-3 d-flex gap-2 flex-wrap"><button class="btn btn-success"><i class="bi bi-check2-circle"></i> حفظ الحضور</button>
        <button type="button" class="btn btn-outline-primary" id="markAll">علّم الفاضي حاضر</button>
        <button type="button" class="btn btn-outline-secondary" id="clearAll">مسح الكل</button></div>
    </form>
  </div>
  <div class="col-xl-4">
    <form method="post" class="card p-3">
      <h6 class="fw-bold"><i class="bi bi-person-plus"></i> عامل يومية جديد (جاي يشتغل كام يوم)</h6>
      <?= csrf_field() ?><input type="hidden" name="action" value="quick_worker"><input type="hidden" name="date" value="<?= e($date) ?>">
      <div class="mb-2"><label class="form-label small">الاسم</label><input name="name" class="form-control" required></div>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">اليومية</label><input name="rate" type="number" step="0.01" min="0" class="form-control num" required></div>
        <div class="col-6"><label class="form-label small">التخصص</label><input name="job" class="form-control" placeholder="تغليف…"></div></div>
      <button class="btn btn-outline-success w-100">إضافة وتسجيله حاضر اليوم ده</button>
      <div class="form-text">بيتضاف في قايمة العاملين بيومية، وتقدر تعدّله بعدين.</div>
    </form>
  </div>
</div>
<script>
const recolor = () => {
  const n = { present: 0, half: 0, rest: 0, absent: 0, none: 0 };
  document.querySelectorAll('select.st').forEach(s => {
    const v = s.value || 'none'; n[v]++;
    s.className = s.className.replace(/\bs-\w+\b/g, '').trim() + (s.value ? ' s-' + s.value : '');
    s.closest('tr').className = 'att-' + v;
  });
  Object.keys(n).forEach(k => { const el = document.getElementById('n-' + k); if (el) el.textContent = n[k]; });
};
document.addEventListener('change', e => { if (e.target.classList && e.target.classList.contains('st')) recolor(); });
document.getElementById('markAll').onclick = () => { document.querySelectorAll('select.st').forEach(s => { if (!s.value) s.value = 'present'; }); recolor(); };
document.getElementById('clearAll').onclick = () => { document.querySelectorAll('select.st').forEach(s => s.value = ''); recolor(); };
recolor();
</script>
<?php require __DIR__ . '/inc/footer.php';
