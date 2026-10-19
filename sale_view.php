<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sales.php';
$u = require_role('store');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    csrf_check();
    if ($u['role'] !== 'admin') { flash('الإلغاء للمدير بس', 'danger'); redirect("sale_view.php?id=$id"); }
    try { void_sale($id); flash('تم إلغاء المستند: الكراتين رجعت لحالتها والمخزن اتعدل', 'warning'); }
    catch (RuntimeException $ex) { flash($ex->getMessage(), 'danger'); }
    redirect("sale_view.php?id=$id");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'meta') {
    csrf_check();
    $clip = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    q('UPDATE sales_invoices SET receiver=?, driver_name=?, car_no=?, organizer=?, notes=? WHERE id=?',
        [$clip('receiver', 150), $clip('driver_name', 100), $clip('car_no', 50), $clip('organizer', 100), $clip('notes', 255), $id]);
    flash('تم حفظ الجهة المستلمة والسائق والسيارة');
    redirect("sale_view.php?id=$id");
}
$inv = q('SELECT s.*, p.name customer, p.code ccode, p.phone, us.full_name usr FROM sales_invoices s JOIN parties p ON p.id=s.customer_id JOIN users us ON us.id=s.user_id WHERE s.id=?', [$id])->fetch();
if (!$inv) { http_response_code(404); exit('غير موجود'); }
$lines = q('SELECT l.*, i.code, i.name, i.unit FROM sales_lines l JOIN items i ON i.id=l.item_id WHERE l.invoice_id=? ORDER BY l.id', [$id])->fetchAll();
$cartons = q('SELECT k.serial, k.net, k.gross, k.cones_count, i.code FROM sales_packages sp JOIN packages k ON k.id=sp.package_id JOIN items i ON i.id=k.finished_item_id WHERE sp.invoice_id=? ORDER BY i.code, k.serial', [$id])->fetchAll();
// wording follows the pack type of the cartons on this document: all sacks -> "شكاير", mixed -> "عبوات", else "كراتين"
$packKinds = q("SELECT DISTINCT CASE WHEN pi.name LIKE '%شكار%' THEN 'sack' ELSE 'carton' END FROM sales_packages sp JOIN packages k ON k.id=sp.package_id LEFT JOIN items pi ON pi.id=k.pack_item_id WHERE sp.invoice_id=?", [$id])->fetchAll(PDO::FETCH_COLUMN);
$packWord = count($packKinds) > 1 ? 'العبوات' : (($packKinds[0] ?? 'carton') === 'sack' ? 'الشكاير' : 'الكراتين');
$paidHere = (float)q('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND voided=0', [$id])->fetchColumn();
$balanceNow = party_balance((int)$inv['customer_id']);
$logo = setting('logo_file');
$isSale = $inv['type'] === 'sale';
$isDelivery = ($inv['kind'] ?? 'invoice') === 'delivery';
$title = sale_title($inv);
$kindKey = $isDelivery ? 'delivery' : 'invoice';
// price settlement: set/adjust the price of each line (a delivery note becomes a priced invoice), discount, and record a payment made now
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'price') {
    csrf_check();
    if ($inv['voided'] || $inv['type'] !== 'sale') { flash('التسعير للمستندات السليمة (غير الملغية) من نوع بيع بس', 'danger'); redirect("sale_view.php?id=$id"); }
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $sub = 0.0;
        foreach (q('SELECT id, qty FROM sales_lines WHERE invoice_id=?', [$id])->fetchAll() as $ln) {
            $price = round(max(0, (float)($_POST['price'][$ln['id']] ?? 0)), 4);
            $amount = round((float)$ln['qty'] * $price, 2);
            q('UPDATE sales_lines SET price=?, amount=? WHERE id=?', [$price, $amount, $ln['id']]);
            $sub += $amount;
        }
        $sub = round($sub, 2);
        $disc = round(max(0, (float)($_POST['discount'] ?? 0)), 2);
        if ($disc > $sub) throw new InvalidArgumentException('الخصم أكبر من الإجمالي');
        q('UPDATE sales_invoices SET subtotal=?, discount=?, total=?, kind=? WHERE id=?', [$sub, $disc, round($sub - $disc, 2), $sub > 0 ? 'invoice' : $inv['kind'], $id]);
        $paidNow = round((float)($_POST['paid_now'] ?? 0), 2);
        if ($paidNow > 0) {
            insert_payment(['type' => 'receipt', 'pay_date' => date('Y-m-d'), 'party_id' => (int)$inv['customer_id'], 'amount' => $paidNow,
                'method' => $_POST['pay_method'] ?? 'cash', 'notes' => 'دفعة على ' . sale_title($inv) . ' رقم ' . $inv['number'], 'invoice_id' => $id], $u['id']);
        }
        $pdo->commit();
        flash('تم حفظ الأسعار' . ($paidNow > 0 ? ' وتسجيل دفعة ' . fmt_qty($paidNow, 2) : '') . ' — رصيد العميل اتحدّث');
    } catch (InvalidArgumentException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        flash($ex->getMessage(), 'danger');
    }
    redirect("sale_view.php?id=$id");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['tpl_save', 'tpl_delete'], true)) {
    csrf_check();
    if ($_POST['action'] === 'tpl_delete') {
        q('DELETE FROM print_templates WHERE id=? AND kind=?', [(int)($_POST['tpl_id'] ?? 0), $kindKey]);
        flash('تم حذف قالب الطباعة');
        redirect("sale_view.php?id=$id");
    }
    $name = mb_substr(trim((string)($_POST['tpl_name'] ?? '')), 0, 80);
    if ($name === '') { flash('اكتب اسم القالب الأول', 'danger'); redirect("sale_view.php?id=$id"); }
    $set = ['title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 80)];
    foreach (PRINT_FIELDS as $k => $_) $set[$k] = isset($_POST['f'][$k]) ? 1 : 0;
    q('INSERT INTO print_templates (name, kind, settings) VALUES (?,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings)', [$name, $kindKey, json_encode($set, JSON_UNESCAPED_UNICODE)]);
    $tid = (int)q('SELECT id FROM print_templates WHERE name=? AND kind=?', [$name, $kindKey])->fetchColumn();
    if (!empty($_POST['make_default'])) {
        q('UPDATE print_templates SET is_default=0 WHERE kind=?', [$kindKey]);
        q('UPDATE print_templates SET is_default=1 WHERE id=?', [$tid]);
    }
    flash('تم حفظ قالب الطباعة «' . $name . '»');
    redirect("sale_view.php?id=$id&tpl=$tid");
}
$tpls = q('SELECT * FROM print_templates WHERE kind=? ORDER BY name', [$kindKey])->fetchAll();
$tplId = (int)($_GET['tpl'] ?? 0);          // 0 = the default template, -1 = the plain built-in layout
$curTpl = null;
foreach ($tpls as $t) { if ($tplId > 0 ? (int)$t['id'] === $tplId : ($tplId === 0 && (int)$t['is_default'] === 1)) { $curTpl = $t; break; } }
$S = print_settings($curTpl, $title);
$hideClasses = implode(' ', array_map(fn($k) => 'hide-' . $k, array_keys(array_filter(array_diff_key($S, ['title' => 1]), fn($v) => !$v))));
// PDF + WhatsApp: the PDF is generated by pdf.php (named "<title> رقم N - <customer>.pdf"); WhatsApp opens the customer's chat with a ready message
$pdfUrl = 'pdf.php?id=' . $id . ($tplId ? '&tpl=' . $tplId : '');
$waPhone = preg_replace('/\D+/', '', (string)($inv['phone'] ?? ''));
if (str_starts_with($waPhone, '00')) $waPhone = substr($waPhone, 2);
elseif (str_starts_with($waPhone, '0')) $waPhone = '20' . substr($waPhone, 1);
$waText = $title . ' رقم ' . $inv['number'] . ' — ' . $inv['customer'] . ' — ' . date('d/m/Y', strtotime($inv['inv_date'])) . "\n" . setting('company_name') . "\n(الملف PDF مرفق)";
$waUrl = 'https://wa.me/' . $waPhone . '?text=' . rawurlencode($waText);
$totalKg = array_sum(array_map(fn($l) => $l['unit'] === 'kg' ? (float)$l['qty'] : 0, $lines));
$totalCartons = array_sum(array_map(fn($l) => (int)$l['packages_count'], $lines));
$totalCones = array_sum(array_map(fn($l) => (int)$l['pieces'], $lines));
$pageTitle = $title . ' رقم ' . $inv['number'];
require __DIR__ . '/inc/header.php';
?>
<style>
.inv { max-width: 900px; margin: 0 auto; }
.sheet { position: relative; }
.sheet-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: .6rem; }
.sheet-head img { height: 78px; }
.sheet-co { text-align: right; line-height: 1.5; }
.sheet-co .co { font-size: 1.3rem; font-weight: 800; }
.sheet-title { text-align: center; margin: .2rem auto .8rem; }
.sheet-title span { display: inline-block; background: #d0d3d8; border: 1px solid #333; padding: .15rem 3rem; font-size: 1.5rem; font-weight: 800; }
.sheet-meta { width: 100%; border-collapse: collapse; margin-bottom: .6rem; }
.sheet-meta td { padding: .25rem .4rem; width: 33%; vertical-align: top; }
.sheet-meta .lbl { color: #222; }
.sheet table.items { width: 100%; border-collapse: collapse; }
.sheet table.items th { background: #d0d3d8; border: 1px solid #333; padding: .3rem .5rem; text-align: center; }
.sheet table.items td { border: 1px solid #333; padding: .3rem .5rem; text-align: center; }
.sheet .totals td { border: 0; font-weight: 700; }
.sheet-sign { display: flex; justify-content: space-between; margin-top: 7rem; padding: 0 2rem; }
.sheet-sign div { text-align: center; min-width: 200px; }
.sheet-sign .line { border-top: 1px solid #333; margin-top: 3rem; padding-top: .2rem; }
.stamp-void { position: absolute; top: 30%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 5rem; font-weight: 800; color: rgba(239, 68, 68, .25); border: 8px solid rgba(239, 68, 68, .25); padding: 0 2rem; border-radius: 20px; pointer-events: none; }
.sheet.hide-cones .f-cones, .sheet.hide-cartons .f-cartons, .sheet.hide-receiver .f-receiver, .sheet.hide-driver .f-driver, .sheet.hide-warehouse .f-warehouse,
.sheet.hide-notes .f-notes, .sheet.hide-balance .f-balance, .sheet.hide-sign_org .f-sign_org, .sheet.hide-sign_rec .f-sign_rec, .sheet.hide-footer .f-footer { display: none; }
@page { size: A4; margin: 0; }   /* margin 0 removes the browser's URL / page-number header and footer */
@media print { body { margin: 0; color: #000 !important; } .sheet, .sheet * { color: #000 !important; opacity: 1 !important; } .sheet { font-weight: 600; } .sheet .lbl { font-weight: 600; } .sheet b, .sheet th, .sheet .co { font-weight: 800; } .inv { max-width: none; } .card { box-shadow: none; } .card.sheet { padding: 16mm 14mm !important; } .sheet table.items th { background: #d0d3d8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; } .sheet-title span { background: #d0d3d8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; } .cartons-page { display: none; page-break-before: always; } body.with-cartons .cartons-page { display: block; } }
</style>
<div class="inv">
  <div class="page-head no-print">
    <h4><?= e($pageTitle) ?> <?php if ($inv['voided']): ?><span class="badge-soft bad">ملغي</span><?php endif; ?></h4>
    <div class="d-flex gap-2 flex-wrap">
      <a href="sales.php" class="btn btn-secondary"><i class="bi bi-arrow-right"></i> رجوع لفواتير البيع</a>
      <button onclick="print()" class="btn btn-primary"><i class="bi bi-printer"></i> طباعة</button>
      <?php if ($cartons): ?><button onclick="const b = document.getElementById('cartonsBox'), was = b.open; b.open = true; document.body.classList.add('with-cartons'); print(); setTimeout(() => { document.body.classList.remove('with-cartons'); b.open = was; }, 500)" class="btn btn-outline-primary"><i class="bi bi-list-ol"></i> طباعة مع بيان <?= $packWord ?></button><?php endif; ?>
      <a href="<?= e($pdfUrl) ?>" class="btn btn-outline-dark"><i class="bi bi-file-earmark-pdf"></i> تحميل PDF</a>
      <?php if ($cartons): ?><a href="<?= e($pdfUrl) ?>&cartons=1" class="btn btn-outline-dark"><i class="bi bi-file-earmark-pdf"></i> PDF مع بيان <?= $packWord ?></a><?php endif; ?>
      <button type="button" class="btn btn-success" id="btnWa" data-pdf="<?= e($pdfUrl) ?>" data-wa="<?= e($waUrl) ?>"><i class="bi bi-whatsapp"></i> إرسال واتساب</button>
      <a href="sale_new.php?type=<?= $inv['type'] ?>" class="btn btn-success"><i class="bi bi-plus"></i> <?= $isSale ? 'مستند بيع جديد' : 'مرتجع جديد' ?></a>
      <a href="party_statement.php?id=<?= $inv['customer_id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-journal-text"></i> كشف حساب العميل</a>
      <?php if (!$inv['voided'] && $u['role'] === 'admin'): ?>
      <form method="post" onsubmit="return confirm('تنبيه: ده إلغاء المستند نفسه (مش رجوع). هيرجّع الكراتين للمخزن ويلغي المدفوع المرتبط بيه. لو عايز ترجع بس اضغط Cancel.\n\nمتأكد إنك عايز تلغي المستند؟')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="void">
        <button class="btn btn-outline-danger ms-4"><i class="bi bi-x-octagon"></i> إلغاء المستند نهائيًا</button></form>
      <?php endif; ?>
    </div>
  </div>
  <details class="card p-3 mb-3 no-print">
    <summary class="fw-bold" style="cursor:pointer"><i class="bi bi-pencil-square"></i> تعديل الجهة المستلمة والسائق ورقم السيارة والملاحظات</summary>
    <form method="post" class="row g-2 mt-1">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="meta">
      <div class="col-md-4"><label class="form-label">الجهة المستلمة</label><input name="receiver" class="form-control" maxlength="150" value="<?= e($inv['receiver'] ?? '') ?>" placeholder="مثال: مصنع الكوثر - العاشر"></div>
      <div class="col-md-4"><label class="form-label">اسم السائق</label><input name="driver_name" class="form-control" maxlength="100" value="<?= e($inv['driver_name'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">رقم السيارة</label><input name="car_no" class="form-control" maxlength="50" value="<?= e($inv['car_no'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">المنظم (الاسم تحت «المنظم»)</label><input name="organizer" class="form-control" maxlength="100" value="<?= e($inv['organizer'] ?? '') ?>" placeholder="لو فاضي بيتسيب فاضي"></div>
      <div class="col-12"><label class="form-label">ملاحظات</label><input name="notes" class="form-control" maxlength="255" value="<?= e($inv['notes'] ?? '') ?>"></div>
      <div class="col-12"><button class="btn btn-success"><i class="bi bi-check2"></i> حفظ</button></div>
    </form>
  </details>
  <?php if (!$inv['voided'] && $isSale): ?>
  <details class="card p-3 mb-3 no-print" <?= $isDelivery ? 'open' : '' ?>>
    <summary class="fw-bold" style="cursor:pointer"><i class="bi bi-cash-stack"></i> التسعير والدفع <?= $isDelivery ? '(الإذن ده من غير سعر لسه)' : '(تعديل الأسعار أو تسجيل دفعة)' ?></summary>
    <form method="post" class="mt-2" id="priceForm">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="price">
      <table class="table table-sm align-middle">
        <thead><tr><th>الصنف</th><th>الكمية</th><th style="width:170px">السعر (للوحدة)</th><th>الإجمالي</th></tr></thead>
        <?php foreach ($lines as $l): ?>
          <tr><td><?= e($l['code']) ?> — <?= e($l['name']) ?></td>
            <td class="num" data-qty="<?= (float)$l['qty'] ?>"><?= fmt_qty($l['qty'], $l['unit'] === 'kg' ? 3 : 0) ?> <?= UNITS[$l['unit']] ?></td>
            <td><input type="number" step="0.01" min="0" name="price[<?= (int)$l['id'] ?>]" class="form-control form-control-sm num pf-price" value="<?= (float)$l['price'] > 0 ? (float)$l['price'] : '' ?>" placeholder="مثال: 95.50"></td>
            <td class="num pf-amount">0.00</td></tr>
        <?php endforeach; ?>
      </table>
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">خصم</label><input type="number" step="0.01" min="0" name="discount" id="pfDisc" class="form-control num" value="<?= (float)$inv['discount'] ?>"></div>
        <div class="col-md-3"><label class="form-label">مدفوع دلوقتي (اختياري)</label><input type="number" step="0.01" min="0" name="paid_now" class="form-control num" placeholder="0"></div>
        <div class="col-md-3"><label class="form-label">طريقة الدفع</label><select name="pay_method" class="form-select"><?php foreach (PAY_METHODS as $k => $lab): ?><option value="<?= $k ?>"><?= e($lab) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><div class="small text-muted">الصافي: <b class="num" id="pfTotal">0.00</b></div><div class="small text-muted">مدفوع قبل كده على المستند: <b class="num"><?= fmt_qty($paidHere, 2) ?></b></div></div>
        <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-success"><i class="bi bi-check2-circle"></i> حفظ الأسعار والدفع</button>
          <a class="btn btn-outline-secondary" href="payments.php?party=<?= (int)$inv['customer_id'] ?>"><i class="bi bi-cash-coin"></i> تسجيل دفعة تانية للعميل</a>
          <a class="btn btn-outline-secondary" href="party_statement.php?id=<?= (int)$inv['customer_id'] ?>"><i class="bi bi-journal-text"></i> كشف حساب العميل</a></div>
        <div class="col-12 text-muted small">رصيد العميل الحالي: <b class="num <?= $balanceNow > 0 ? 'text-danger' : 'text-success' ?>"><?= fmt_qty(abs($balanceNow), 2) ?></b> <?= $balanceNow > 0 ? 'عليه' : ($balanceNow < 0 ? 'له' : '') ?>. بعد الحفظ بيتحسب الباقي عليه = الصافي − اللي دفعه.</div>
      </div>
    </form>
  </details>
  <script>
  (() => {
    const f = document.getElementById('priceForm'), fmt = v => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const calc = () => {
      let sub = 0;
      f.querySelectorAll('tbody tr, table tr').forEach(tr => {
        const q = parseFloat(tr.querySelector('[data-qty]')?.dataset.qty), p = parseFloat(tr.querySelector('.pf-price')?.value);
        if (isNaN(q)) return;
        const a = isNaN(p) ? 0 : Math.round(q * p * 100) / 100; tr.querySelector('.pf-amount').textContent = fmt(a); sub += a;
      });
      document.getElementById('pfTotal').textContent = fmt(Math.max(0, sub - (parseFloat(document.getElementById('pfDisc').value) || 0)));
    };
    f.addEventListener('input', calc); calc();
  })();
  </script>
  <?php endif; ?>
  <details class="card p-3 mb-3 no-print" <?= $tplId ? 'open' : '' ?>>
    <summary class="fw-bold" style="cursor:pointer"><i class="bi bi-sliders"></i> تصميم الطباعة (عنوان الورقة والخانات + قوالب محفوظة)</summary>
    <div class="row g-2 mt-1">
      <div class="col-md-5"><label class="form-label">القالب المستخدم</label>
        <select class="form-select" onchange="location.href='sale_view.php?id=<?= $id ?>&tpl=' + this.value">
          <option value="-1" <?= $tplId === -1 || (!$curTpl && $tplId <= 0) ? 'selected' : '' ?>>— التصميم الأساسي (كله) —</option>
          <?php foreach ($tpls as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $curTpl && (int)$curTpl['id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?><?= $t['is_default'] ? ' (الافتراضي)' : '' ?></option><?php endforeach; ?>
        </select></div>
      <?php if ($curTpl): ?>
      <div class="col-md-3 d-flex align-items-end">
        <form method="post" onsubmit="return confirm('حذف قالب الطباعة ده؟')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="tpl_delete"><input type="hidden" name="tpl_id" value="<?= (int)$curTpl['id'] ?>">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> حذف القالب ده</button></form></div>
      <?php endif; ?>
    </div>
    <form method="post" class="row g-2 mt-1" id="tplForm">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="tpl_save">
      <div class="col-md-6"><label class="form-label">عنوان الورقة</label><input name="title" class="form-control" maxlength="80" value="<?= e($S['title']) ?>"></div>
      <div class="col-12"><div class="form-label mb-1">اللي يتطبع في الورقة (شيل العلامة عشان تخفيه):</div>
        <?php foreach (PRINT_FIELDS as $k => $label): if ($k === 'balance' && $isDelivery) continue; ?>
          <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="f[<?= $k ?>]" data-k="<?= $k ?>" <?= $S[$k] ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?></div>
      <div class="col-md-4"><label class="form-label">احفظ التصميم ده باسم</label><input name="tpl_name" class="form-control" maxlength="80" value="<?= e($curTpl['name'] ?? '') ?>" placeholder="مثال: إذن بدون كونات"></div>
      <div class="col-md-4 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="make_default" value="1" <?= $curTpl && $curTpl['is_default'] ? 'checked' : '' ?>> اجعله الافتراضي لكل المستندات من النوع ده</label></div>
      <div class="col-md-4 d-flex align-items-end"><button class="btn btn-success"><i class="bi bi-save"></i> حفظ القالب</button></div>
      <div class="col-12 text-muted small">التغييرات بتظهر في الورقة فورًا للمعاينة والطباعة. لو عايزها تتحفظ لكل مرة، اكتب اسم واضغط «حفظ القالب».</div>
    </form>
  </details>
  <div id="waHint" class="alert alert-success no-print d-none"><i class="bi bi-whatsapp"></i> الـPDF اتحمّل باسم «<?= e($title . ' رقم ' . $inv['number'] . ' - ' . $inv['customer']) ?>». المحادثة اتفتحت على واتساب<?= $waPhone ? '' : ' (اختار العميل لأن مفيش رقم تليفون متسجل ليه)' ?> — اضغط 📎 وأرفق الملف من التحميلات وابعت.</div>
  <?php if (isset($_GET['new'])): ?><div class="alert alert-success no-print"><i class="bi bi-check-circle"></i> تم حفظ <?= e($title) ?> رقم <?= (int)$inv['number'] ?> بنجاح</div><?php endif; ?>
  <div class="card p-4 sheet <?= $hideClasses ?>" id="sheet">
    <?php if ($inv['voided']): ?><div class="stamp-void">ملغي</div><?php endif; ?>
    <div class="sheet-head">
      <div class="sheet-co"><div class="co"><?= e(setting('company_name')) ?></div><div><?= e(setting('company_address')) ?></div>
        <div class="num"><?= e(setting('company_phone1')) ?></div><div class="num"><?= e(setting('company_phone2')) ?></div></div>
      <div><?php if ($logo && is_file(__DIR__ . '/' . $logo)): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><img src="assets/brand.svg" alt=""><?php endif; ?></div>
    </div>
    <div class="sheet-title"><span id="sheetTitle"><?= e($S['title']) ?></span></div>
    <table class="sheet-meta">
      <tr><td><span class="lbl">رقم <?= $isDelivery ? 'الإذن' : 'الفاتورة' ?> :</span> <b><?= (int)$inv['number'] ?></b></td>
          <td class="f-receiver"><span class="lbl">الجهة المستلمة :</span> <b><?= e($inv['receiver'] ?? '') ?></b></td>
          <td><span class="lbl">اسم العميل :</span> <b><?= e($inv['customer']) ?></b></td></tr>
      <tr><td><span class="lbl">التاريخ :</span> <b class="num"><?= e(date('d/m/Y', strtotime($inv['inv_date']))) ?></b></td>
          <td class="f-warehouse"><span class="lbl">المستودع :</span> <b>المستودع الرئيسي</b></td>
          <td class="f-driver"><span class="lbl">اسم السائق :</span> <b><?= e($inv['driver_name'] ?? '') ?></b></td></tr>
      <tr><td><span class="lbl">رقم الوثيقة :</span> <b><?= (int)$inv['number'] ?></b></td>
          <td></td>
          <td class="f-driver"><span class="lbl">رقم السيارة :</span> <b><?= e($inv['car_no'] ?? '') ?></b></td></tr>
      <tr class="f-notes"><td colspan="3"><span class="lbl">الملاحظات :</span> <?= e($inv['notes'] ?? '') ?></td></tr>
    </table>
    <table class="items">
      <thead><tr><th>رقم المادة</th><th>اسم المادة</th><th class="f-cartons">عدد <?= $packWord ?></th><th class="f-cones">الكونات</th><th>الكمية</th><th>الوحدة</th><?php if (!$isDelivery): ?><th>السعر</th><th>الإجمالي</th><?php endif; ?></tr></thead>
      <?php foreach ($lines as $l): ?>
        <tr><td class="num"><?= e($l['code']) ?></td><td><?= e($l['name']) ?></td><td class="f-cartons"><?= $l['packages_count'] ?: '' ?></td><td class="f-cones"><?= $l['pieces'] ?: '' ?></td>
          <td class="num"><?= fmt_qty($l['qty'], $l['unit'] === 'kg' ? 2 : 0) ?></td><td><?= UNITS[$l['unit']] ?></td>
          <?php if (!$isDelivery): ?><td class="num"><?= fmt_qty($l['price'], 2) ?></td><td class="num fw-bold"><?= fmt_qty($l['amount'], 2) ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      <tr class="totals"><td colspan="2" class="text-start">الإجمالي</td><td class="f-cartons"><?= $totalCartons ?: '' ?></td><td class="f-cones"><?= $totalCones ?: '' ?></td><td class="num"><?= fmt_qty($totalKg, 2) ?></td><td><?= $totalKg > 0 ? 'كيلو' : '' ?></td>
        <?php if (!$isDelivery): ?><td></td><td class="num"><?= fmt_qty($inv['subtotal'], 2) ?></td><?php endif; ?></tr>
      <?php if (!$isDelivery && $inv['discount'] > 0): ?><tr class="totals"><td colspan="7" class="text-start">خصم</td><td class="num">- <?= fmt_qty($inv['discount'], 2) ?></td></tr><?php endif; ?>
      <?php if (!$isDelivery): ?><tr class="totals"><td colspan="7" class="text-start">الصافي</td><td class="num fs-5"><?= fmt_qty($inv['total'], 2) ?></td></tr><?php endif; ?>
      <?php if (!$isDelivery && $paidHere > 0): ?><tr class="totals"><td colspan="7" class="text-start"><?= $isSale ? 'المدفوع مع الفاتورة' : 'اترجع للعميل' ?></td><td class="num"><?= fmt_qty($paidHere, 2) ?></td></tr><?php endif; ?>
    </table>
    <?php if (!$isDelivery): ?>
      <div class="mt-2 small f-balance">رصيد العميل الحالي: <b class="num <?= $balanceNow > 0 ? 'text-danger' : 'text-success' ?>"><?= fmt_qty(abs($balanceNow), 2) ?></b> <?= $balanceNow > 0 ? 'عليه' : ($balanceNow < 0 ? 'له' : '') ?></div>
    <?php endif; ?>
    <div class="sheet-sign">
      <div class="f-sign_org">المنظم<div class="line"><?= e($inv['organizer'] ?? '') ?: '&nbsp;' ?></div></div>
      <div class="f-sign_rec">اسم وتوقيع المستلم<div class="line">&nbsp;</div></div>
    </div>
    <div class="text-muted text-center mt-3 f-footer" dir="ltr"><?= e(setting('company_name')) ?></div>
    <?php if ($cartons): ?>
      <details class="cartons-page mt-4" id="cartonsBox">
      <summary class="fw-bold" style="cursor:pointer">بيان <?= $packWord ?> (<?= count($cartons) ?>) — إجمالي الصافي <span class="num"><?= fmt_qty(array_sum(array_column($cartons, 'net')), 3) ?></span> كيلو <span class="text-muted small no-print">(اضغط للعرض)</span></summary>
      <table class="table table-sm table-bordered small mt-2">
        <thead><tr><th>#</th><th>الباركود</th><th>الكود</th><th>كونات</th><th>قائم</th><th>صافي</th></tr></thead>
        <?php foreach ($cartons as $i => $c): ?><tr><td><?= $i + 1 ?></td><td class="num"><?= e($c['serial']) ?></td><td><?= e($c['code']) ?></td><td><?= (int)$c['cones_count'] ?></td><td class="num"><?= fmt_qty($c['gross'], 2) ?></td><td class="num"><?= fmt_qty($c['net'], 3) ?></td></tr><?php endforeach; ?>
      </table>
      </details>
    <?php endif; ?>
  </div>
</div>
<script>
document.getElementById('btnWa')?.addEventListener('click', e => {
  const b = e.currentTarget, a = document.createElement('a');
  a.href = b.dataset.pdf; a.download = ''; document.body.appendChild(a); a.click(); a.remove();     // 1) download the PDF
  window.open(b.dataset.wa, '_blank');                                                              // 2) open the customer's WhatsApp chat with the message
  document.getElementById('waHint').classList.remove('d-none');
});
// live preview of the print settings panel (nothing is saved until "حفظ القالب")
(() => {
  const sheet = document.getElementById('sheet'), form = document.getElementById('tplForm'), titleEl = document.getElementById('sheetTitle');
  const fallback = <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>;
  const apply = () => {
    form.querySelectorAll('input[data-k]').forEach(cb => sheet.classList.toggle('hide-' + cb.dataset.k, !cb.checked));
    titleEl.textContent = form.elements['title'].value.trim() || fallback;
  };
  form.addEventListener('input', apply);
  form.addEventListener('change', apply);
})();
</script>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', () => setTimeout(() => print(), 400));</script><?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
