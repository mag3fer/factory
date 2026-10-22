<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
require_role('store');

$type = ($_GET['type'] ?? '') === 'return' ? 'return' : 'sale';
$customers = q("SELECT id, code, name FROM parties WHERE active=1 AND type='customer' ORDER BY name")->fetchAll();
$manualItems = q("SELECT id, code, name, unit FROM items WHERE active=1 ORDER BY type, code")->fetchAll();
$pageTitle = $type === 'sale' ? 'فاتورة بيع جديدة' : 'مرتجع بيع جديد';
$pageScripts = ['assets/sale.js?v=' . filemtime(__DIR__ . '/assets/sale.js')];
require __DIR__ . '/inc/header.php';
?>
<style>
.scan-box { position: relative; }
.scan-box input { height: 64px; font-size: 1.6rem; font-weight: 700; direction: ltr; text-align: center; letter-spacing: 2px; border-radius: 14px; border: 2px dashed #94a3b8; }
.scan-box input:focus { border-style: solid; border-color: var(--tl-cyan); }
.scan-box .beam { position: absolute; left: 12px; right: 12px; top: 50%; height: 2px; background: linear-gradient(90deg, transparent, #ef4444, transparent); opacity: .0; pointer-events: none; }
.scan-box input:focus + .beam { animation: beam 1.6s ease-in-out infinite; }
@keyframes beam { 0%, 100% { transform: translateY(-22px); opacity: .7; } 50% { transform: translateY(22px); opacity: .7; } }
.pkg-row-new { animation: rowIn .5s ease; }
@keyframes rowIn { from { background: #dcfce7; transform: translateX(-12px); } to { background: transparent; transform: none; } }
.total-big { font-size: 2rem; font-weight: 800; direction: ltr; }
.sale-mode { border-top: 4px solid <?= $type === 'sale' ? 'var(--tl-green)' : 'var(--tl-amber)' ?>; }
#sale.delivery .price-only { display: none !important; }
#sale.delivery .delivery-only { display: block !important; }
#sale.delivery .sale-mode { border-top-color: var(--tl-blue); }
</style>
<div id="sale" data-type="<?= $type ?>" data-csrf="<?= csrf_token() ?>">
<div class="page-head">
  <h4><i class="bi bi-<?= $type === 'sale' ? 'upc-scan' : 'arrow-return-right' ?>"></i> <?= $pageTitle ?></h4>
  <div><a class="btn btn-outline-secondary btn-sm" href="sale_new.php?type=<?= $type === 'sale' ? 'return' : 'sale' ?>"><?= $type === 'sale' ? 'عمل مرتجع بدل كده' : 'فاتورة بيع بدل كده' ?></a></div>
</div>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card p-3 mb-3 sale-mode reveal">
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label">العميل</label>
          <select id="customer" class="form-select form-select-lg"><option value="">— اختر العميل —</option>
            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?><?= $c['code'] ? ' (' . e($c['code']) . ')' : '' ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">التاريخ</label><input id="invDate" type="date" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-3 d-flex align-items-end"><div class="w-100 small text-muted" id="custBalance"></div></div>
      </div>
      <?php if ($type === 'sale'): ?>
      <div class="btn-group mt-3" role="group" aria-label="نوع المستند">
        <input type="radio" class="btn-check" name="kind" id="kindInvoice" value="invoice" checked><label class="btn btn-outline-success" for="kindInvoice"><i class="bi bi-receipt"></i> فاتورة بيع (بسعر)</label>
        <input type="radio" class="btn-check" name="kind" id="kindDelivery" value="delivery"><label class="btn btn-outline-primary" for="kindDelivery"><i class="bi bi-truck"></i> إذن تسليم / مذكرة إخراج (بدون سعر)</label>
      </div>
      <?php endif; ?>
      <div class="row g-2 mt-1">
        <div class="col-md-3"><label class="form-label">الجهة المستلمة</label><input id="receiver" class="form-control" maxlength="150" placeholder="مثال: مصنع الكوثر - العاشر"></div>
        <div class="col-md-3"><label class="form-label">اسم السائق</label><input id="driverName" class="form-control" maxlength="100"></div>
        <div class="col-md-3"><label class="form-label">رقم السيارة</label><input id="carNo" class="form-control" maxlength="50"></div>
        <div class="col-md-3"><label class="form-label">هاتف مندوب الاستلام <span class="text-muted small">(مش تبعنا — للسائق)</span></label><input id="contactPhone" class="form-control num" maxlength="40" inputmode="tel" dir="ltr"></div>
        <div class="col-md-3"><label class="form-label">المنظم</label><input id="organizer" class="form-control" maxlength="100" placeholder="اختياري - لو فاضي بيتسيب فاضي"></div>
      </div>
    </div>
    <div class="card p-3 mb-3 reveal">
      <label class="form-label fw-bold"><i class="bi bi-upc"></i> امسح باركود الكرتونة <?= $type === 'return' ? 'المرتجعة' : '' ?> (أو اكتبه ودوس Enter)</label>
      <div class="scan-box"><input id="scan" class="form-control" autocomplete="off" placeholder="2610110001"><div class="beam"></div></div>
      <div id="scanMsg" class="mt-2"></div>
      <?php if ($type === 'sale'): ?>
      <div class="mt-3"><button type="button" class="btn btn-outline-primary" id="btnPick"><i class="bi bi-boxes"></i> اختيار من المخزن (بالتات وكراتين) من غير باركود</button></div>
      <link rel="stylesheet" href="assets/sale_pick.css?v=<?= filemtime(__DIR__ . '/assets/sale_pick.css') ?>">
      <div id="pickPanel" class="sp-panel mt-3 d-none">
        <div class="sp-bar">
          <div class="sp-search"><i class="bi bi-search"></i><input id="pickFilter" class="form-control" placeholder="ابحث بالنمرة أو الصنف أو رقم البالتة أو الكرتونة أو اللوط"></div>
          <div class="sp-chips" id="pickChips">
            <button type="button" data-f="all" class="on">الكل</button><button type="button" data-f="carton"><i class="bi bi-box-seam"></i> كراتين</button><button type="button" data-f="sack"><i class="bi bi-bag"></i> شكاير</button>
            <button type="button" data-f="std">نظامي</button><button type="button" data-f="mixed">متعدد</button><button type="button" data-f="full">بالتات كاملة</button>
          </div>
          <button type="button" class="btn btn-outline-secondary sp-reload" id="pickReload" title="تحديث"><i class="bi bi-arrow-repeat"></i></button>
        </div>
        <div class="sp-sum" id="pickSum"></div>
        <div id="pickList" class="sp-list"></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="card p-0 mb-3 reveal">
      <div class="p-3 pb-2 card-title-row"><h6>الكراتين (<span id="pkgCount">0</span>)</h6><span class="text-muted small">إجمالي الوزن: <b class="num" id="pkgKg">0</b> كيلو</span></div>
      <div class="px-3" id="palChips"></div>
      <div class="table-responsive" style="max-height:340px">
      <table class="table table-sm table-hover" id="pkgTable">
        <thead><tr><th>#</th><th>الباركود</th><th>الكود</th><th>النمرة</th><th>كونات</th><th>صافي (كيلو)</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
      </div>
    </div>
    <div class="card p-3 reveal">
      <div class="card-title-row"><h6>أصناف من غير باركود (عوادم، كونات، ...)</h6><button class="btn btn-sm btn-outline-secondary" id="addManual"><i class="bi bi-plus"></i> صنف</button></div>
      <table class="table table-sm" id="manualTable"><thead><tr><th style="width:50%">الصنف</th><th>الكمية</th><th class="price-only">السعر</th><th class="price-only">الإجمالي</th><th></th></tr></thead><tbody></tbody></table>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card p-3 reveal" style="position:sticky;top:80px">
      <div class="price-only">
      <h6 class="fw-bold">الأسعار حسب النمرة</h6>
      <table class="table table-sm" id="priceTable"><thead><tr><th>النمرة</th><th>كيلو</th><th style="width:34%">سعر الكيلو</th><th>الإجمالي</th></tr></thead><tbody></tbody></table>
      <hr>
      <div class="d-flex justify-content-between mb-2"><span>الإجمالي</span><b class="num" id="subtotal">0.00</b></div>
      <div class="input-group input-group-sm mb-2"><span class="input-group-text">خصم</span><input id="discount" type="number" step="0.01" min="0" class="form-control num" value="0"></div>
      <div class="text-center my-2"><div class="text-muted small">الصافي</div><div class="total-big text-<?= $type === 'sale' ? 'success' : 'warning' ?>" id="total">0.00</div></div>
      <div class="input-group input-group-sm mb-2"><span class="input-group-text"><?= $type === 'sale' ? 'مدفوع دلوقتي' : 'اترجع للعميل' ?></span><input id="paid" type="number" step="0.01" min="0" class="form-control num" value="0">
        <select id="payMethod" class="form-select" style="max-width:110px"><?php foreach (PAY_METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="delivery-only text-center my-2 d-none"><div class="text-muted small">إجمالي الوزن</div><div class="total-big text-primary"><span id="kgBig">0.000</span> <small class="fs-6">كيلو</small></div></div>
      <input id="notes" class="form-control form-control-sm mb-3" placeholder="ملاحظات">
      <button class="btn btn-<?= $type === 'sale' ? 'success' : 'warning' ?> btn-lg w-100" id="btnSave" disabled><i class="bi bi-check2-circle"></i> حفظ <?= $type === 'sale' ? 'الفاتورة' : 'المرتجع' ?></button>
      <div id="saveMsg" class="mt-2"></div>
    </div>
  </div>
</div>
</div>
<template id="manualTpl"><tr>
  <td><select class="form-select form-select-sm m-item"><option value="">— اختر —</option><?php foreach ($manualItems as $i): ?><option value="<?= $i['id'] ?>"><?= e($i['code'] . ' — ' . $i['name']) ?> (<?= UNITS[$i['unit']] ?>)</option><?php endforeach; ?></select></td>
  <td><input type="number" step="0.001" min="0" class="form-control form-control-sm num m-qty"></td>
  <td class="price-only"><input type="number" step="0.01" min="0" class="form-control form-control-sm num m-price"></td>
  <td class="num m-amount price-only">0.00</td><td><button class="btn btn-sm btn-outline-danger m-del"><i class="bi bi-x"></i></button></td>
</tr></template>
<?php require __DIR__ . '/inc/footer.php';
