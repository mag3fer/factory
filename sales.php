<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
$u = require_role('store');
require __DIR__ . '/inc/hr.php';

// ---- trips decided from the invoice list: add a trip (driver now, cost later), change its driver, remove it, or mark "no trip" ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    $invId = (int)($_POST['invoice_id'] ?? 0);
    $back = 'sales.php' . (!empty($_POST['back']) ? '?' . preg_replace('/[^A-Za-z0-9=&_%.-]/', '', (string)$_POST['back']) : '');
    try {
        $inv = q("SELECT id, number, inv_date, type FROM sales_invoices WHERE id=? AND voided=0", [$invId])->fetch();
        if (!$inv || $inv['type'] !== 'sale') throw new InvalidArgumentException('الفاتورة مش موجودة أو ملغية');
        $pdo = db();
        if ($act === 'add_trip') {
            $load = invoice_load($invId);
            $notes = mb_substr(trim((string)($_POST['notes'] ?? '')) ?: trim((string)$load['notes']), 0, 255) ?: null;   // empty -> the invoice's own notes
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $payNow = !empty($_POST['pay_now']);
            $pdo->beginTransaction();
            if (($_POST['driver_id'] ?? '') === '__new') {
                // a new / outside driver paid right away: he becomes a trip-paid driver ("سواق خارجي") so later trips can pick him from the list
                $name = mb_substr(trim((string)($_POST['ext_name'] ?? '')), 0, 120);
                if ($name === '') throw new InvalidArgumentException('اكتب اسم السواق');
                $driverId = (int)q("SELECT id FROM workers WHERE name=? AND role='goods_driver' ORDER BY active DESC, id LIMIT 1", [$name])->fetchColumn();
                if (!$driverId) {
                    q("INSERT INTO workers (name, phone, role, job, pay_type, active) VALUES (?,?, 'goods_driver', 'سواق خارجي', 'trip', 1)",
                      [$name, mb_substr(trim((string)($_POST['ext_phone'] ?? '')), 0, 40) ?: null]);
                    $driverId = (int)$pdo->lastInsertId();
                } else q('UPDATE workers SET active=1 WHERE id=?', [$driverId]);
                $external = true;
            } else {
                $driverId = (int)($_POST['driver_id'] ?? 0);
                $w = q("SELECT job FROM workers WHERE id=? AND active=1 AND role IN ('goods_driver','bus_driver')", [$driverId])->fetch();
                if (!$w) throw new InvalidArgumentException('اختار السواق');
                $external = $w['job'] === 'سواق خارجي';
            }
            if (!$external) { $amount = 0.0; $payNow = false; }   // regular drivers: the cost is set later from the trips page
            elseif ($amount <= 0) throw new InvalidArgumentException('اكتب مبلغ المشوار للسواق الخارجي');
            q('INSERT INTO trips (trip_date, driver_id, kind, amount, invoice_id, notes, user_id) VALUES (?,?,?,?,?,?,?)', [$inv['inv_date'], $driverId, 'goods', $amount, $invId, $notes, $u['id']]);
            $tid = (int)$pdo->lastInsertId();
            q('UPDATE sales_invoices SET no_trip=0 WHERE id=?', [$invId]);
            if ($amount > 0) {
                $note = 'مشوار رقم ' . $tid . ' — فاتورة ' . (int)$inv['number'] . ' — ' . $load['customer'] . ' — ' . load_text($load);
                ledger_add(['worker_id' => $driverId, 'kind' => 'trip', 'amount' => $amount, 'entry_date' => $inv['inv_date'], 'ref_id' => $tid, 'notes' => $note], $u['id']);
                if ($payNow) ledger_add(['worker_id' => $driverId, 'kind' => 'payment', 'amount' => $amount, 'entry_date' => date('Y-m-d'), 'ref_id' => $tid, 'notes' => 'اتدفع كاش وقت المشوار رقم ' . $tid], $u['id']);
            }
            $pdo->commit();
            flash('اتسجّل مشوار للفاتورة رقم ' . (int)$inv['number'] . ($amount > 0 ? ' — ' . money($amount) . ($payNow ? ' (اتدفع)' : ' (على حساب السواق)') : ' — التكلفة تتحدد بعدين من صفحة المشاوير'));
        } elseif ($act === 'set_driver') {
            $tid = (int)($_POST['trip_id'] ?? 0);
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $t = q('SELECT * FROM trips WHERE id=? AND invoice_id=? AND voided=0', [$tid, $invId])->fetch();
            if (!$t) throw new InvalidArgumentException('المشوار مش موجود');
            if (!q("SELECT id FROM workers WHERE id=? AND active=1 AND role IN ('goods_driver','bus_driver')", [$driverId])->fetch()) throw new InvalidArgumentException('اختار السواق');
            $pdo->beginTransaction();
            q('UPDATE trips SET driver_id=? WHERE id=?', [$driverId, $tid]);
            q("UPDATE hr_ledger SET worker_id=? WHERE kind='trip' AND ref_id=? AND voided=0", [$driverId, $tid]);   // a cost already posted moves to the new driver's account
            $pdo->commit();
            flash('اتغيّر سواق المشوار');
        } elseif ($act === 'remove_trip') {
            $tid = (int)($_POST['trip_id'] ?? 0);
            $pdo->beginTransaction();
            q('UPDATE trips SET voided=1 WHERE id=? AND invoice_id=?', [$tid, $invId]);
            q("UPDATE hr_ledger SET voided=1 WHERE kind='trip' AND ref_id=?", [$tid]);
            $pdo->commit();
            flash('اتشال المشوار من الفاتورة', 'warning');
        } elseif ($act === 'no_trip') {
            if ((int)q('SELECT COUNT(*) FROM trips WHERE invoice_id=? AND voided=0', [$invId])->fetchColumn() > 0) throw new InvalidArgumentException('الفاتورة دي عليها مشوار — شيله الأول');
            q('UPDATE sales_invoices SET no_trip=1 WHERE id=?', [$invId]);
            flash('الفاتورة رقم ' . (int)$inv['number'] . ' — ملهاش مشوار');
        } elseif ($act === 'clear_no_trip') {
            q('UPDATE sales_invoices SET no_trip=0 WHERE id=?', [$invId]);
            flash('رجّعت الفاتورة رقم ' . (int)$inv['number'] . ' لحالة «لسه ما اتحددش مشوارها»');
        }
    } catch (InvalidArgumentException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash($ex->getMessage(), 'danger');
    }
    redirect($back);
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$cust = (int)($_GET['customer'] ?? 0);
$type = isset(SALE_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';

$where = ['s.inv_date BETWEEN ? AND ?']; $params = [$from, $to];
if ($cust) { $where[] = 's.customer_id=?'; $params[] = $cust; }
if ($type) { $where[] = 's.type=?'; $params[] = $type; }
$tripF = in_array($_GET['trip'] ?? '', ['pending', 'with', 'none'], true) ? $_GET['trip'] : '';   // trip decided for the invoice?
$hasTrip = "EXISTS (SELECT 1 FROM trips t WHERE t.invoice_id=s.id AND t.voided=0)";
if ($tripF === 'pending') $where[] = "s.type='sale' AND s.voided=0 AND s.no_trip=0 AND NOT $hasTrip";
if ($tripF === 'with') $where[] = $hasTrip;
if ($tripF === 'none') $where[] = 's.no_trip=1';
$rows = q('SELECT s.*, p.name customer, (SELECT COALESCE(SUM(qty),0) FROM sales_lines WHERE invoice_id=s.id) kg,
                  (SELECT COUNT(*) FROM sales_packages WHERE invoice_id=s.id) cartons,
                  (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=s.id AND voided=0) paid
           FROM sales_invoices s JOIN parties p ON p.id=s.customer_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.inv_date DESC, s.id DESC', $params)->fetchAll();
$customers = q("SELECT id, name FROM parties WHERE type='customer' ORDER BY name")->fetchAll();
$drivers = q("SELECT id, name, job FROM workers WHERE active=1 AND role IN ('goods_driver','bus_driver') ORDER BY name")->fetchAll();
$tripsBy = [];   // invoice id => its trips (driver and cost; the cost is shown to the admin only)
if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    foreach (q("SELECT t.id, t.invoice_id, t.amount, t.driver_id, w.name driver FROM trips t JOIN workers w ON w.id=t.driver_id WHERE t.voided=0 AND t.invoice_id IN ($ids) ORDER BY t.id")->fetchAll() as $t) $tripsBy[(int)$t['invoice_id']][] = $t;
}
$isAdmin = $u['role'] === 'admin';
$backQs = http_build_query(array_filter(['from' => $from, 'to' => $to, 'customer' => $cust ?: '', 'type' => $type, 'trip' => $tripF], fn($v) => $v !== ''));
$tot = ['kg' => 0, 'amount' => 0];
foreach ($rows as $r) if (!$r['voided']) { $s = $r['type'] === 'sale' ? 1 : -1; $tot['kg'] += $s * $r['kg']; $tot['amount'] += $s * $r['total']; }
$pageTitle = 'فواتير البيع';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <h4><i class="bi bi-receipt"></i> فواتير البيع والمرتجعات</h4>
  <div class="d-flex gap-2"><a href="sale_new.php" class="btn btn-success"><i class="bi bi-upc-scan"></i> فاتورة بيع / إذن تسليم</a><a href="sale_new.php?type=return" class="btn btn-outline-warning"><i class="bi bi-arrow-return-right"></i> مرتجع</a></div>
</div>
<form class="card p-3 mb-3 row g-2 mx-0 no-print reveal">
  <div class="col-md-2"><label class="form-label small">من</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
  <div class="col-md-2"><label class="form-label small">إلى</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  <div class="col-md-3"><label class="form-label small">العميل</label><select name="customer" class="form-select"><option value="">الكل</option>
    <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $cust === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small">النوع</label><select name="type" class="form-select"><option value="">الكل</option>
    <?php foreach (SALE_TYPES as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small">المشوار</label><select name="trip" class="form-select"><option value="">الكل</option>
    <option value="pending"<?= $tripF === 'pending' ? ' selected' : '' ?>>لسه ما اتحددش</option><option value="with"<?= $tripF === 'with' ? ' selected' : '' ?>>عليها مشوار</option><option value="none"<?= $tripF === 'none' ? ' selected' : '' ?>>ملهاش مشوار</option></select></div>
  <div class="col-md-1 d-flex align-items-end gap-2"><button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button><button type="button" onclick="print()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i></button></div>
</form>
<div class="row g-3 mb-3">
  <div class="col-md-4 reveal"><div class="card kpi"><div class="ic g-blue"><i class="bi bi-receipt"></i></div><div><div class="lbl">عدد الفواتير</div><div class="value"><span data-count="<?= count($rows) ?>">0</span></div></div></div></div>
  <div class="col-md-4 reveal"><div class="card kpi"><div class="ic g-cyan"><i class="bi bi-box-seam"></i></div><div><div class="lbl">صافي الكمية (كيلو)</div><div class="value num"><span data-count="<?= round($tot['kg'], 1) ?>" data-dec="1">0</span></div></div></div></div>
  <div class="col-md-4 reveal"><div class="card kpi"><div class="ic g-green"><i class="bi bi-cash-stack"></i></div><div><div class="lbl">صافي المبيعات</div><div class="value num"><span data-count="<?= round($tot['amount'], 2) ?>" data-dec="2">0</span></div></div></div></div>
</div>
<div class="card table-wrap reveal">
<table class="table table-hover">
  <thead><tr><th>النوع</th><th>رقم</th><th>التاريخ</th><th>العميل</th><th>كراتين</th><th>كيلو</th><th>الصافي</th><th>المدفوع معاها</th><th class="no-print">المشوار</th><th></th></tr></thead>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['voided'] ? 'text-decoration-line-through text-muted' : '' ?>">
      <td><span class="badge-soft <?= $r['type'] !== 'sale' ? 'warn' : (($r['kind'] ?? 'invoice') === 'delivery' ? 'info' : 'ok') ?>"><?= e(sale_title($r)) ?></span></td><td class="fw-bold"><?= (int)$r['number'] ?></td><td><?= e($r['inv_date']) ?></td>
      <td><?= e($r['customer']) ?></td><td><?= (int)$r['cartons'] ?: '' ?></td><td class="num"><?= fmt_qty($r['kg'], 1) ?></td><td class="num fw-bold"><?= fmt_qty($r['total'], 2) ?></td>
      <td class="num"><?= $r['paid'] > 0 ? fmt_qty($r['paid'], 2) : '' ?></td>
      <td class="no-print" style="min-width:210px">
      <?php if ($r['type'] === 'sale' && !$r['voided']): $tr = $tripsBy[(int)$r['id']] ?? [];
          $ld = invoice_load((int)$r['id']);   // the load and notes of the invoice travel with the trip (the driver is paid by load and destination)
          $attrs = ' data-notes="' . e((string)$ld['notes']) . '" data-load="' . e(load_text($ld)) . '" data-cust="' . e($ld['customer']) . '"'; ?>
        <?php foreach ($tr as $t): ?>
          <div class="d-flex align-items-center gap-1 mb-1">
            <span class="badge bg-info text-dark"><i class="bi bi-truck"></i> <?= e($t['driver']) ?></span>
            <?php if ($isAdmin): ?><span class="small <?= (float)$t['amount'] > 0 ? 'num fw-bold' : 'text-warning-emphasis' ?>"><?= (float)$t['amount'] > 0 ? fmt_qty($t['amount'], 2) : 'التكلفة لسه' ?></span><?php endif; ?>
            <button type="button" class="btn btn-sm btn-link p-0 ms-auto trip-edit"<?= $attrs ?> data-inv="<?= (int)$r['id'] ?>" data-no="<?= (int)$r['number'] ?>" data-trip="<?= (int)$t['id'] ?>" data-driver="<?= (int)$t['driver_id'] ?>" title="غيّر السواق أو شيل المشوار"><i class="bi bi-pencil"></i></button>
          </div>
        <?php endforeach; ?>
        <?php if ($tr && load_text($ld)): ?><div class="small text-muted mb-1"><i class="bi bi-box-seam"></i> <?= e(load_text($ld)) ?></div><?php endif; ?>
        <?php if (!$tr && $r['no_trip']): ?>
          <span class="text-muted small">ملهوش مشوار</span>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear_no_trip"><input type="hidden" name="invoice_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($backQs) ?>"><button class="btn btn-sm btn-link p-0" title="رجّعها لحالة لسه ما اتحددش">تعديل</button></form>
        <?php elseif (!$tr): ?>
          <button type="button" class="btn btn-sm btn-outline-primary trip-add"<?= $attrs ?> data-inv="<?= (int)$r['id'] ?>" data-no="<?= (int)$r['number'] ?>"><i class="bi bi-plus"></i> مشوار</button>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="no_trip"><input type="hidden" name="invoice_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($backQs) ?>"><button class="btn btn-sm btn-outline-secondary">ملهوش</button></form>
        <?php endif; ?>
        <?php if ($tr): ?><button type="button" class="btn btn-sm btn-link p-0 trip-add small"<?= $attrs ?> data-inv="<?= (int)$r['id'] ?>" data-no="<?= (int)$r['number'] ?>">+ مشوار تاني</button><?php endif; ?>
      <?php endif; ?></td>
      <td class="no-print"><a class="btn btn-sm btn-outline-primary" href="sale_view.php?id=<?= $r['id'] ?>"><i class="bi bi-eye"></i></a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4"><i class="bi bi-inbox fs-3 d-block"></i>مفيش فواتير في الفترة دي</td></tr><?php endif; ?>
</table>
</div>
<div class="modal fade" id="tripModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
  <?= csrf_field() ?><input type="hidden" name="action" id="tmAction" value="add_trip"><input type="hidden" name="invoice_id" id="tmInv"><input type="hidden" name="trip_id" id="tmTrip"><input type="hidden" name="back" value="<?= e($backQs) ?>">
  <div class="modal-header"><h5 class="modal-title" id="tmTitle">مشوار للفاتورة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <label class="form-label">السواق</label>
    <select name="driver_id" id="tmDriver" class="form-select form-select-lg" required><option value="">— اختر السواق —</option>
      <?php foreach ($drivers as $d): ?><option value="<?= $d['id'] ?>" data-ext="<?= $d['job'] === 'سواق خارجي' ? 1 : 0 ?>"><?= e($d['name']) ?><?= $d['job'] === 'سواق خارجي' ? ' (خارجي)' : '' ?></option><?php endforeach; ?>
      <option value="__new" data-ext="1">＋ سواق جديد / خارجي (بحاسبه دلوقتي)…</option></select>
    <div id="tmExt" class="border rounded p-3 mt-3 bg-light d-none">
      <div id="tmNewBox" class="row g-2 mb-2 d-none">
        <div class="col-7"><label class="form-label small">اسم السواق</label><input name="ext_name" id="tmExtName" class="form-control" maxlength="120" placeholder="اسم السواق" list="tmExtNames"></div>
        <div class="col-5"><label class="form-label small">تليفون <span class="text-muted">(اختياري)</span></label><input name="ext_phone" class="form-control" maxlength="40"></div>
        <datalist id="tmExtNames"><?php foreach ($drivers as $d): ?><option value="<?= e($d['name']) ?>"><?php endforeach; ?></datalist>
      </div>
      <label class="form-label small">مبلغ المشوار</label>
      <input type="number" step="0.01" min="0.01" name="amount" id="tmAmount" class="form-control form-control-lg num" placeholder="0.00">
      <label class="form-check mt-2"><input type="checkbox" class="form-check-input" name="pay_now" value="1" checked> <span class="fw-bold">اتدفع دلوقتي (كاش)</span> <span class="text-muted small">— ولو شيلتها بيتسجل على حسابه</span></label>
    </div>
    <div class="alert alert-secondary py-2 mt-3 mb-0" id="tmInfo"><div><b>العميل:</b> <span id="tmCust"></span></div><div><b>الحمولة:</b> <span id="tmLoad"></span></div></div>
    <div id="tmNotesBox" class="mt-3"><label class="form-label">ملاحظات المشوار <span class="text-muted small">(واخدها من ملاحظات الفاتورة — تقدر تعدّلها)</span></label><textarea name="notes" id="tmNotes" class="form-control" rows="3" maxlength="255" placeholder="وجهة المشوار"></textarea></div>
    <div class="form-text mt-2">التكلفة مش بتتكتب هنا — بتتحدد بعدين من صفحة المشاوير <?= $isAdmin ? '' : '(المدير)' ?> وتتسجل في حساب السواق وقتها.</div>
  </div>
  <div class="modal-footer justify-content-between">
    <button type="submit" class="btn btn-outline-danger d-none" id="tmRemove" name="action" value="remove_trip" onclick="return confirm('تشيل المشوار ده من الفاتورة؟')"><i class="bi bi-trash"></i> شيل المشوار</button>
    <button type="submit" class="btn btn-success ms-auto" id="tmSave"><i class="bi bi-check2"></i> حفظ</button>
  </div>
</form></div></div>
<script>
window.addEventListener('load', () => {   // Bootstrap's script comes with the footer, after this block
  const modal = new bootstrap.Modal(document.getElementById('tripModal'));
  const $ = id => document.getElementById(id);
  const open = (mode, b) => {
    $('tmInv').value = b.dataset.inv; $('tmTrip').value = b.dataset.trip || '';
    $('tmAction').value = mode === 'edit' ? 'set_driver' : 'add_trip';
    $('tmTitle').textContent = (mode === 'edit' ? 'مشوار فاتورة رقم ' : 'مشوار جديد لفاتورة رقم ') + b.dataset.no;
    $('tmDriver').value = mode === 'edit' ? b.dataset.driver : '';
    $('tmNotesBox').classList.toggle('d-none', mode === 'edit');
    $('tmRemove').classList.toggle('d-none', mode !== 'edit');
    $('tmCust').textContent = b.dataset.cust || '—'; $('tmLoad').textContent = b.dataset.load || '—';   // what the driver carries and for whom
    $('tmNotes').value = b.dataset.notes || '';                                                         // the invoice's own notes (where it goes), editable
    $('tmAmount').value = ''; $('tmExtName').value = '';
    [...$('tmDriver').options].forEach(o => { if (o.value === '__new') o.hidden = o.disabled = mode === 'edit'; });   // a new driver can only be added with a new trip
    syncExt();
    modal.show();
  };
  // a new / outside driver is paid for the trip right away: show the name + amount + "paid now" fields
  const syncExt = () => {
    const sel = $('tmDriver'), adding = $('tmAction').value === 'add_trip';
    const ext = adding && sel.selectedOptions[0]?.dataset.ext === '1', isNew = adding && sel.value === '__new';
    $('tmExt').classList.toggle('d-none', !ext);
    $('tmNewBox').classList.toggle('d-none', !isNew);
    $('tmAmount').required = ext;
    $('tmExtName').required = isNew;
  };
  $('tmDriver').addEventListener('change', syncExt);
  document.querySelectorAll('.trip-add').forEach(b => b.addEventListener('click', () => open('add', b)));
  document.querySelectorAll('.trip-edit').forEach(b => b.addEventListener('click', () => open('edit', b)));
});
</script>
<?php require __DIR__ . '/inc/footer.php';
