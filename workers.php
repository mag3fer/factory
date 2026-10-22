<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hr.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $role = isset(WORKER_ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'worker';
    $pay = isset(PAY_BASIS[$_POST['pay_type'] ?? '']) ? $_POST['pay_type'] : 'daily';
    $shift = isset(SHIFTS[$_POST['shift'] ?? '']) ? $_POST['shift'] : null;
    if ($name === '') { flash('اكتب اسم العامل', 'danger'); redirect('workers.php'); }
    $vals = [$name, trim($_POST['phone'] ?? '') ?: null, $role, mb_substr(trim($_POST['job'] ?? ''), 0, 80) ?: null, $pay,
             round(max(0, (float)($_POST['rate'] ?? 0)), 2), round(max(0, (float)($_POST['ot_rate'] ?? 0)), 2), $shift, isset($_POST['active']) ? 1 : 0, trim($_POST['notes'] ?? '') ?: null,
             preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start_date'] ?? '') ? $_POST['start_date'] : null, max(0, min(31, (int)($_POST['rest_days'] ?? 0)))];
    if ($id) q('UPDATE workers SET name=?, phone=?, role=?, job=?, pay_type=?, rate=?, ot_rate=?, shift=?, active=?, notes=?, start_date=?, rest_days=? WHERE id=?', [...$vals, $id]);
    else q('INSERT INTO workers (name, phone, role, job, pay_type, rate, ot_rate, shift, active, notes, start_date, rest_days) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
    flash('تم حفظ ' . $name);
    redirect('workers.php');
}

$edit = isset($_GET['edit']) ? q('SELECT * FROM workers WHERE id=?', [(int)$_GET['edit']])->fetch() : null;
$filter = isset(WORKER_ROLES[$_GET['role'] ?? '']) ? $_GET['role'] : '';
$all = isset($_GET['all']);
$rows = q('SELECT * FROM workers WHERE (? = "" OR role=?)' . ($all ? '' : ' AND active=1') . ' ORDER BY role, name', [$filter, $filter])->fetchAll();
$bal = hr_balances();
$due = [];
foreach ($rows as $w) $due[(int)$w['id']] = round(($bal[(int)$w['id']] ?? 0) + accrued_wage($w, date('Y-m-d'))['wage'], 2);
$owe = array_sum(array_map(fn($v) => max(0, $v), $due));
$pageTitle = 'العاملين والسواقين';
require __DIR__ . '/inc/header.php';
$f = $edit ?: ['id' => 0, 'name' => '', 'phone' => '', 'role' => 'worker', 'job' => '', 'pay_type' => 'daily', 'rate' => '', 'ot_rate' => '', 'shift' => '', 'active' => 1, 'notes' => '', 'start_date' => '', 'rest_days' => 0];
?>
<div class="page-head"><h4><i class="bi bi-people"></i> العاملين والسواقين</h4>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-sm <?= $filter === '' ? 'btn-dark' : 'btn-outline-dark' ?>" href="workers.php<?= $all ? '?all=1' : '' ?>">الكل</a>
    <?php foreach (WORKER_ROLES as $k => $l): ?><a class="btn btn-sm <?= $filter === $k ? 'btn-dark' : 'btn-outline-dark' ?>" href="workers.php?role=<?= $k ?><?= $all ? '&all=1' : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
    <a class="btn btn-sm btn-outline-secondary" href="workers.php?<?= $filter ? 'role=' . $filter . '&' : '' ?><?= $all ? '' : 'all=1' ?>"><?= $all ? 'النشطين بس' : 'مع الموقوفين' ?></a>
  </div></div>
<div class="row g-3">
  <div class="col-xl-4">
    <form method="post" class="card p-3">
      <h6 class="fw-bold mb-3"><i class="bi bi-person-plus"></i> <?= $f['id'] ? 'تعديل: ' . e($f['name']) : 'عامل / سواق جديد' ?></h6>
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <div class="mb-2"><label class="form-label small">الاسم</label><input name="name" class="form-control" required maxlength="120" value="<?= e($f['name']) ?>"></div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small">الوظيفة (النوع)</label><select name="role" class="form-select"><?php foreach (WORKER_ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $f['role'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small">التخصص (اختياري)</label><input name="job" class="form-control" maxlength="80" value="<?= e($f['job'] ?? '') ?>" placeholder="تغليف / على الماكينة…"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small">طريقة الحساب</label><select name="pay_type" class="form-select"><?php foreach (PAY_BASIS as $k => $l): ?><option value="<?= $k ?>"<?= $f['pay_type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small">المرتب الشهري / اليومية</label><input name="rate" type="number" step="0.01" min="0" class="form-control num" value="<?= e((string)$f['rate']) ?>"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-12"><label class="form-label small">سعر ساعة الإضافي (اختياري)</label><input name="ot_rate" type="number" step="0.01" min="0" class="form-control num" value="<?= e((string)$f['ot_rate']) ?>"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-7"><label class="form-label small">بداية الشغل</label><input name="start_date" type="date" class="form-control" value="<?= e((string)($f['start_date'] ?? '')) ?>"></div>
        <div class="col-5"><label class="form-label small">راحات في الشهر</label><input name="rest_days" type="number" min="0" max="31" class="form-control num" value="<?= (int)($f['rest_days'] ?? 0) ?>"></div>
      </div>
      <div class="mb-2"><label class="form-label small">تليفون</label><input name="phone" class="form-control" maxlength="40" value="<?= e($f['phone'] ?? '') ?>"></div>
      <div class="mb-2"><label class="form-label small">ملاحظات</label><input name="notes" class="form-control" maxlength="255" value="<?= e($f['notes'] ?? '') ?>"></div>
      <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active"<?= $f['active'] ? ' checked' : '' ?>> شغّال حاليًا</label>
      <div class="d-flex gap-2"><button class="btn btn-success"><i class="bi bi-check2"></i> حفظ</button><?php if ($f['id']): ?><a class="btn btn-secondary" href="workers.php">جديد</a><?php endif; ?></div>
      <div class="form-text mt-2">السائق اللي بيتحاسب بالمشوار اختار له «بالمشوار»، ومشاويره بتتحسب لوحدها من شاشة المشاوير.</div>
    </form>
  </div>
  <div class="col-xl-8">
    <div class="card p-0 table-wrap">
      <table class="table table-sm table-hover mb-0">
        <thead><tr><th>الاسم</th><th>الوظيفة</th><th>الحساب</th><th>المبلغ</th><th>الرصيد المسجّل</th><th>المستحق لحد النهارده</th><th></th></tr></thead>
        <?php foreach ($rows as $w): $b = $bal[(int)$w['id']] ?? 0.0; ?>
          <tr class="<?= $w['active'] ? '' : 'text-muted' ?>"><td class="fw-bold"><?= e($w['name']) ?><?= $w['active'] ? '' : ' <span class="badge-soft warn">موقوف</span>' ?></td>
            <td><?= e(WORKER_ROLES[$w['role']]) ?><?= $w['job'] ? ' — ' . e($w['job']) : '' ?></td><td><?= e(PAY_BASIS[$w['pay_type']]) ?></td>
            <td class="num"><?= $w['pay_type'] === 'trip' ? '—' : money((float)$w['rate']) ?></td>
            <td class="num <?= $b < -0.004 ? 'neg' : '' ?>"><?= money(abs($b)) ?> <small class="text-muted"><?= $b > 0.004 ? 'له' : ($b < -0.004 ? 'عليه' : '') ?></small></td>
            <?php $dv = $due[(int)$w['id']] ?? 0.0; ?><td class="num fw-bold <?= $dv < -0.004 ? 'neg' : 'text-success' ?>"><?= money(abs($dv)) ?> <small class="text-muted"><?= $dv > 0.004 ? 'له' : ($dv < -0.004 ? 'عليه' : '') ?></small></td>
            <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="hr_statement.php?id=<?= $w['id'] ?>">كشف حساب</a>
              <a class="btn btn-sm btn-outline-secondary" href="workers.php?edit=<?= $w['id'] ?>">تعديل</a></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">مفيش عاملين لسه — ضيف أول واحد من الفورم</td></tr><?php endif; ?>
        <tfoot class="table-light"><tr><th colspan="5">إجمالي المستحق للعاملين لحد النهارده</th><th class="num"><?= money($owe) ?></th><th></th></tr></tfoot>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
