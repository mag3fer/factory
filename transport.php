<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hr.php';
require __DIR__ . '/inc/sales.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'void') {
        $tid = (int)$_POST['id'];
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE trips SET voided=1 WHERE id=?', [$tid]);
        q("UPDATE hr_ledger SET voided=1 WHERE kind='trip' AND ref_id=?", [$tid]);
        $pdo->commit();
        flash('تم إلغاء المشوار', 'warning');
        redirect('transport.php');
    }
    if (($_POST['action'] ?? '') === 'set_amount') {      // set (or correct) the cost of a trip any time later
        try {
            $tid = (int)$_POST['id'];
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $t = q('SELECT * FROM trips WHERE id=? AND voided=0', [$tid])->fetch();
            if (!$t) throw new InvalidArgumentException('المشوار غير موجود أو ملغي');
            if ($amount <= 0) throw new InvalidArgumentException('اكتب مبلغ أكبر من صفر');
            $pdo = db();
            $pdo->beginTransaction();
            q('UPDATE trips SET amount=? WHERE id=?', [$amount, $tid]);
            $has = q("SELECT id FROM hr_ledger WHERE kind='trip' AND ref_id=? AND voided=0", [$tid])->fetchColumn();
            if ($has) q('UPDATE hr_ledger SET amount=? WHERE id=?', [$amount, $has]);
            else {
                $ld = $t['invoice_id'] ? invoice_load((int)$t['invoice_id']) : null;   // the driver's statement shows where he went and what he carried
                ledger_add(['worker_id' => (int)$t['driver_id'], 'kind' => 'trip', 'amount' => $amount, 'entry_date' => $t['trip_date'], 'ref_id' => $tid,
                            'notes' => 'مشوار رقم ' . $tid . ' — ' . TRIP_KINDS[$t['kind']] . ($ld ? ' — فاتورة ' . (int)$ld['number'] . ' — ' . $ld['customer'] . ' — ' . load_text($ld) : '')], $u['id']);
            }
            $pdo->commit();
            flash('تم تحديد تكلفة المشوار رقم ' . $tid . ' — ' . money($amount));
        } catch (InvalidArgumentException $ex) {
            if (db()->inTransaction()) db()->rollBack();
            flash($ex->getMessage(), 'danger');
        }
        redirect('transport.php' . (!empty($_POST['back']) ? '?' . preg_replace('/[^A-Za-z0-9=&_%.-]/', '', (string)$_POST['back']) : ''));
    }
    try {
        $kind = isset(TRIP_KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : '';
        $date = (string)($_POST['trip_date'] ?? '');
        $veh = q('SELECT * FROM vehicles WHERE id=?', [(int)($_POST['vehicle_id'] ?? 0)])->fetch();
        $driverId = (int)($_POST['driver_id'] ?? 0) ?: (int)($veh['driver_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        if (!$kind) throw new InvalidArgumentException('اختار نوع المشوار');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('تاريخ غير صحيح');
        if (!$driverId || !q('SELECT id FROM workers WHERE id=?', [$driverId])->fetch()) throw new InvalidArgumentException('اختار المركبة أو السواق');
        if ($amount < 0) throw new InvalidArgumentException('المبلغ مينفعش يكون بالسالب');
        $shift = null;   // no shifts
        if ($kind === 'other' && trim((string)($_POST['notes'] ?? '')) === '') throw new InvalidArgumentException('اكتب وصف المشوار الخاص (رايح فين / ليه) في الملاحظات');
        $inv = $kind === 'goods' ? ((int)($_POST['invoice_id'] ?? 0) ?: null) : null;
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255) ?: null;
        $pdo = db();
        $pdo->beginTransaction();
        q('INSERT INTO trips (trip_date, vehicle_id, driver_id, kind, amount, shift, invoice_id, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$date, $veh['id'] ?? null, $driverId, $kind, $amount, $shift, $inv, $notes, $u['id']]);
        $tid = (int)$pdo->lastInsertId();
        if ($amount > 0) {
            ledger_add(['worker_id' => $driverId, 'kind' => 'trip', 'amount' => $amount, 'entry_date' => $date, 'ref_id' => $tid,
                        'notes' => 'مشوار رقم ' . $tid . ' — ' . TRIP_KINDS[$kind] . ($inv ? (function () use ($inv) { $l = invoice_load($inv); return ' — فاتورة ' . (int)$l['number'] . ' — ' . $l['customer'] . ' — ' . load_text($l); })() : '')], $u['id']);
        }
        $pdo->commit();
        flash('تم تسجيل المشوار رقم ' . $tid . ($amount > 0 ? ' — ' . money($amount) : ' — من غير تكلفة لسه (تقدر تحطها في أي وقت من القايمة)'));
    } catch (InvalidArgumentException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash($ex->getMessage(), 'danger');
    }
    redirect('transport.php');
}

$ok = fn(string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : $def;
$from = $ok('from', date('Y-m-01'));
$to = $ok('to', date('Y-m-d'));
$fd = (int)($_GET['driver'] ?? 0);
$vehicles = q("SELECT v.*, w.name driver FROM vehicles v LEFT JOIN workers w ON w.id=v.driver_id WHERE v.active=1 ORDER BY v.kind, v.name")->fetchAll();
$drivers = q("SELECT id, name FROM workers WHERE active=1 AND role IN ('goods_driver','bus_driver') ORDER BY name")->fetchAll();
$rates = q('SELECT code, price FROM trip_rates')->fetchAll(PDO::FETCH_KEY_PAIR);
$notes = q("SELECT s.id, s.number, s.inv_date, p.name FROM sales_invoices s JOIN parties p ON p.id=s.customer_id WHERE s.type='sale' AND s.voided=0 ORDER BY s.id DESC LIMIT 60")->fetchAll();
$trips = q("SELECT t.*, w.name driver, v.name vehicle, s.number inv_no FROM trips t JOIN workers w ON w.id=t.driver_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN sales_invoices s ON s.id=t.invoice_id
            WHERE t.trip_date BETWEEN ? AND ? AND (?=0 OR t.driver_id=?) ORDER BY t.trip_date DESC, t.id DESC", [$from, $to, $fd, $fd])->fetchAll();
$byDriver = []; $byKind = []; $total = 0.0; $noCost = 0;
foreach ($trips as $t) if (!$t['voided']) {
    if ((float)$t['amount'] <= 0) $noCost++;
    $byDriver[$t['driver']] = ($byDriver[$t['driver']] ?? ['n' => 0, 'amt' => 0.0]); $byDriver[$t['driver']]['n']++; $byDriver[$t['driver']]['amt'] += (float)$t['amount'];
    $byKind[$t['kind']] = ($byKind[$t['kind']] ?? ['n' => 0, 'amt' => 0.0]); $byKind[$t['kind']]['n']++; $byKind[$t['kind']]['amt'] += (float)$t['amount'];
    $total += (float)$t['amount'];
}
$pageTitle = 'المشاوير';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head"><h4><i class="bi bi-signpost-split"></i> المشاوير (نقل البضاعة وميكروباص العمال)</h4><a class="btn btn-outline-secondary btn-sm" href="vehicles.php"><i class="bi bi-gear"></i> المركبات والأسعار</a></div>
<div class="row g-3">
  <div class="col-xl-4">
    <form method="post" class="card p-3" id="tripForm">
      <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle"></i> مشوار جديد</h6>
      <?= csrf_field() ?>
      <div class="mb-2"><label class="form-label small">نوع المشوار</label>
        <?php $i = 0; foreach (TRIP_KINDS as $k => $l): ?><label class="form-check d-block"><input class="form-check-input" type="radio" name="kind" value="<?= $k ?>" data-price="<?= e((string)($rates[$k] ?? 0)) ?>"<?= $i++ === 0 ? ' checked' : '' ?>> <?= e($l) ?> <?= $k === 'other' ? '<span class="text-muted small">(اكتب المبلغ والوصف)</span>' : '<span class="text-muted small num">(' . money((float)($rates[$k] ?? 0)) . ')</span>' ?></label><?php endforeach; ?></div>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">التاريخ</label><input type="date" name="trip_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-6"><label class="form-label small">المبلغ <span class="text-muted">(اختياري)</span></label><input type="number" step="0.01" min="0" name="amount" id="amount" class="form-control num fs-5" placeholder="سيبه فاضي وحطه بعدين"></div></div>
      <div class="mb-2"><label class="form-label small">المركبة</label><select name="vehicle_id" id="vehicle" class="form-select"><option value="">— اختر —</option>
        <?php foreach ($vehicles as $v): ?><option value="<?= $v['id'] ?>" data-driver="<?= (int)$v['driver_id'] ?>"><?= e($v['name']) ?><?= $v['plate'] ? ' (' . e($v['plate']) . ')' : '' ?> — <?= e($v['driver'] ?? 'من غير سواق') ?></option><?php endforeach; ?></select></div>
      <div class="mb-2"><label class="form-label small">السواق (لو مختلف عن سواق المركبة)</label><select name="driver_id" id="driver" class="form-select"><option value="">— سواق المركبة —</option>
        <?php foreach ($drivers as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="mb-2" id="goodsBox"><label class="form-label small">إذن التسليم / الفاتورة (اختياري)</label><select name="invoice_id" class="form-select"><option value="">— من غير ربط —</option>
        <?php foreach ($notes as $n): ?><option value="<?= $n['id'] ?>">#<?= (int)$n['number'] ?> — <?= e($n['name']) ?> — <?= e($n['inv_date']) ?></option><?php endforeach; ?></select></div>
      <div class="mb-3"><label class="form-label small" id="notesLbl">ملاحظات</label><input name="notes" id="notes" class="form-control" maxlength="255" placeholder="مثال: توصيل لمصنع الكوثر - العاشر من رمضان"></div>
      <button class="btn btn-success"><i class="bi bi-check2"></i> حفظ المشوار</button>
      <div class="form-text mt-2">سجّل المشوار وقت ما يحصل، والتكلفة تحطها في أي وقت (آخر الشهر مثلاً) من القايمة. المشوار بيتسجل في حساب السواق أول ما تتحدد تكلفته.</div>
    </form>
  </div>
  <div class="col-xl-8">
    <form class="card p-3 mb-3" method="get"><div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label small">من</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
      <div class="col-md-3"><label class="form-label small">إلى</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
      <div class="col-md-4"><label class="form-label small">السواق</label><select name="driver" class="form-select"><option value="0">الكل</option><?php foreach ($drivers as $d): ?><option value="<?= $d['id'] ?>"<?= $fd === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><button class="btn btn-primary w-100">عرض</button></div></div></form>
    <div class="row g-2 mb-3">
      <div class="col-md-4"><div class="card p-3"><div class="text-muted small">إجمالي تكلفة النقل (على المصنع)</div><div class="fs-4 fw-bold num"><?= money($total) ?></div><div class="small text-muted"><?= array_sum(array_column($byKind, 'n')) ?> مشوار<?= $noCost ? ' · <b class="text-warning-emphasis">' . $noCost . ' من غير تكلفة لسه</b>' : '' ?></div></div></div>
      <div class="col-md-8"><div class="card p-3 h-100"><div class="small">
        <?php foreach ($byKind as $k => $v): ?><div class="d-flex justify-content-between"><span><?= e(TRIP_KINDS[$k]) ?> (<?= $v['n'] ?>)</span><b class="num"><?= money($v['amt']) ?></b></div><?php endforeach; if (!$byKind) echo '<span class="text-muted">مفيش مشاوير في الفترة دي</span>'; ?></div></div></div>
    </div>
    <?php if ($byDriver): ?><div class="card p-0 table-wrap mb-3"><table class="table table-sm mb-0"><thead><tr><th>السواق</th><th>عدد المشاوير</th><th>المبلغ</th></tr></thead>
      <?php foreach ($byDriver as $n => $v): ?><tr><td class="fw-bold"><?= e($n) ?></td><td><?= $v['n'] ?></td><td class="num"><?= money($v['amt']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
    <div class="card p-0 table-wrap"><table class="table table-sm mb-0"><thead><tr><th>رقم</th><th>التاريخ</th><th>السواق</th><th>المركبة</th><th>النوع</th><th>المبلغ</th><th>الفاتورة والحمولة والملاحظات</th><th></th></tr></thead>
      <?php foreach ($trips as $t): ?>
        <tr class="<?= $t['voided'] ? 'text-decoration-line-through text-muted' : ((float)$t['amount'] <= 0 ? 'table-warning' : '') ?>"><td class="fw-bold"><?= (int)$t['id'] ?></td><td><?= e($t['trip_date']) ?></td><td><a href="hr_statement.php?id=<?= $t['driver_id'] ?>"><?= e($t['driver']) ?></a></td><td class="small"><?= e($t['vehicle'] ?? '') ?></td>
          <td class="small"><?= e(TRIP_KINDS[$t['kind']]) ?></td>
          <td style="min-width:150px"><?php if ($t['voided']): ?><span class="num"><?= money((float)$t['amount']) ?></span>
            <?php else: ?><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="set_amount"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="back" value="from=<?= e($from) ?>&to=<?= e($to) ?>&driver=<?= $fd ?>">
              <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm num fw-bold" style="width:90px" value="<?= (float)$t['amount'] > 0 ? (float)$t['amount'] : '' ?>" placeholder="التكلفة" required>
              <button class="btn btn-sm <?= (float)$t['amount'] > 0 ? 'btn-outline-secondary' : 'btn-success' ?>" title="حفظ التكلفة"><i class="bi bi-check2"></i></button></form><?php endif; ?></td>
          <td class="small"><?php if ($t['invoice_id']): $ld = invoice_load((int)$t['invoice_id']); ?>
            <div><a href="sale_view.php?id=<?= (int)$t['invoice_id'] ?>">إذن/فاتورة <?= (int)$ld['number'] ?></a> — <b><?= e($ld['customer']) ?></b></div>
            <?php if (load_text($ld)): ?><div><i class="bi bi-box-seam"></i> <b>الحمولة:</b> <?= e(load_text($ld)) ?></div><?php endif; ?>
            <div class="text-muted"><?= e(($t['notes'] ?? '') !== '' ? $t['notes'] : (string)$ld['notes']) ?></div>
          <?php else: ?><?= e($t['notes'] ?? '') ?><?php endif; ?></td>
          <td><?php if (!$t['voided']): ?><form method="post" onsubmit="return confirm('إلغاء المشوار ده؟ هيتشال من حساب السواق.')"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></form><?php endif; ?></td></tr>
      <?php endforeach; if (!$trips): ?><tr><td colspan="8" class="text-center text-muted py-3">مفيش مشاوير</td></tr><?php endif; ?></table></div>
  </div>
</div>
<script>
(() => {
  const f = document.getElementById('tripForm'), amount = document.getElementById('amount');
  let touched = false; amount.addEventListener('input', () => touched = true);
  const sync = () => {
    const r = f.querySelector('input[name="kind"]:checked'); if (!r) return;
    if (!touched) amount.value = parseFloat(r.dataset.price) > 0 ? r.dataset.price : '';
    const bus = r.value.startsWith('bus'), other = r.value === 'other';
    document.getElementById('goodsBox').classList.toggle('d-none', bus || other);
    document.getElementById('notes').required = other;
    document.getElementById('notesLbl').textContent = other ? 'وصف المشوار الخاص (مطلوب)' : 'ملاحظات';
    if (other) amount.focus();
  };
  f.querySelectorAll('input[name="kind"]').forEach(r => r.addEventListener('change', () => { touched = false; sync(); }));
  sync();
})();
</script>
<?php require __DIR__ . '/inc/footer.php';
