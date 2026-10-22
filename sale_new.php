<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales.php';
require_role('store');

$type = ($_GET['type'] ?? '') === 'return' ? 'return' : 'sale';
// customers with what the picker shows: phone, balance, how many invoices and the last one
$customers = q("SELECT p.id, p.code, p.name, p.phone,
                  (SELECT COUNT(*) FROM sales_invoices s WHERE s.customer_id=p.id AND s.voided=0 AND s.type='sale') n_inv,
                  (SELECT MAX(s.inv_date) FROM sales_invoices s WHERE s.customer_id=p.id AND s.voided=0) last_inv
                FROM parties p WHERE p.active=1 AND p.type='customer' ORDER BY p.name")->fetchAll();
foreach ($customers as &$c) $c['bal'] = party_balance((int)$c['id']);
unset($c);
$manualItems = q("SELECT id, code, name, unit FROM items WHERE active=1 ORDER BY type, code")->fetchAll();
$pageTitle = $type === 'sale' ? 'فاتورة بيع جديدة' : 'مرتجع بيع جديد';
$pageScripts = ['assets/cust_pick.js?v=' . filemtime(__DIR__ . '/assets/cust_pick.js'), 'assets/sale.js?v=' . filemtime(__DIR__ . '/assets/sale.js')];
require __DIR__ . '/inc/header.php';
$isSale = $type === 'sale';
?>
<link rel="stylesheet" href="assets/sale.css?v=<?= filemtime(__DIR__ . '/assets/sale.css') ?>">
<link rel="stylesheet" href="assets/sale_pick.css?v=<?= filemtime(__DIR__ . '/assets/sale_pick.css') ?>">
<div id="sale" class="sl <?= $isSale ? 'is-sale' : 'is-return' ?>" data-type="<?= $type ?>" data-csrf="<?= csrf_token() ?>">

  <!-- ===== header ===== -->
  <section class="sl-hero reveal">
    <div class="sl-hero-bg"><i></i><i></i></div>
    <div class="sl-hero-main">
      <div class="sl-hero-id">
        <div class="sl-hero-ic"><i class="bi bi-<?= $isSale ? 'receipt-cutoff' : 'arrow-return-right' ?>" id="heroIcon"></i></div>
        <div><h3 id="heroTitle"><?= $pageTitle ?></h3><div class="sl-hero-sub" id="heroSub"><?= $isSale ? 'امسح الكراتين أو اختار البالتات من المخزن، حط الأسعار واحفظ' : 'امسح الكراتين اللي رجعت من العميل واحفظ المرتجع' ?></div></div>
      </div>
      <a class="btn sl-switch" href="sale_new.php?type=<?= $isSale ? 'return' : 'sale' ?>"><i class="bi bi-arrow-left-right"></i> <?= $isSale ? 'عمل مرتجع بدل كده' : 'فاتورة بيع بدل كده' ?></a>
    </div>
    <ol class="sl-steps" id="steps">
      <li data-s="customer"><span>1</span>العميل</li>
      <li data-s="goods"><span>2</span><?= $isSale ? 'الكراتين' : 'المرتجع' ?></li>
      <li data-s="price" class="price-only"><span>3</span>الأسعار</li>
      <li data-s="save"><span>4</span>الحفظ</li>
    </ol>
  </section>

  <div class="sl-grid">
    <div class="sl-main">

      <!-- ===== customer & document ===== -->
      <section class="sl-card sale-mode reveal">
        <div class="sl-card-h"><h6><i class="bi bi-person-badge"></i> بيانات <?= $isSale ? 'الفاتورة' : 'المرتجع' ?></h6><span class="sl-bal" id="custBalance"></span></div>
        <div class="row g-3">
          <div class="col-md-8"><label class="form-label">العميل</label>
            <div class="sl-ig"><i class="bi bi-person"></i><select id="customer" class="form-select form-select-lg"><option value="">— اختر العميل —</option>
              <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-code="<?= e((string)$c['code']) ?>" data-phone="<?= e((string)$c['phone']) ?>"
                data-bal="<?= round((float)$c['bal'], 2) ?>" data-n="<?= (int)$c['n_inv'] ?>" data-last="<?= e((string)$c['last_inv']) ?>"><?= e($c['name']) ?><?= $c['code'] ? ' (' . e($c['code']) . ')' : '' ?></option><?php endforeach; ?></select></div></div>
          <div class="col-md-4"><label class="form-label">التاريخ</label><div class="sl-ig"><i class="bi bi-calendar-event"></i><input id="invDate" type="date" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>"></div></div>
        </div>
        <?php if ($isSale): ?>
        <div class="sl-kind" role="group" aria-label="نوع المستند">
          <input type="radio" class="btn-check" name="kind" id="kindInvoice" value="invoice">
          <label for="kindInvoice" class="k-inv"><i class="bi bi-receipt"></i><b>فاتورة بيع</b><small>بسعر — بتتحسب على العميل</small></label>
          <input type="radio" class="btn-check" name="kind" id="kindDelivery" value="delivery" checked>
          <label for="kindDelivery" class="k-del"><i class="bi bi-truck"></i><b>إذن تسليم / مذكرة إخراج</b><small>بدون سعر — بتتطبع على طول</small></label>
        </div>
        <?php endif; ?>
        <div class="sl-sub"><i class="bi bi-truck"></i> بيانات التسليم <small>(اختياري)</small></div>
        <div class="row g-2">
          <div class="col-md-4"><div class="sl-ig sm"><i class="bi bi-building"></i><input id="receiver" class="form-control" maxlength="150" placeholder="الجهة المستلمة — مثال: مصنع الكوثر"></div></div>
          <div class="col-md-4"><div class="sl-ig sm"><i class="bi bi-person-vcard"></i><input id="driverName" class="form-control" maxlength="100" placeholder="اسم السائق"></div></div>
          <div class="col-md-4"><div class="sl-ig sm"><i class="bi bi-car-front"></i><input id="carNo" class="form-control" maxlength="50" placeholder="رقم السيارة"></div></div>
          <div class="col-md-6"><div class="sl-ig sm"><i class="bi bi-telephone"></i><input id="contactPhone" class="form-control num" maxlength="40" inputmode="tel" dir="ltr" placeholder="هاتف مندوب الاستلام (مش تبعنا — للسائق)"></div></div>
          <div class="col-md-6"><div class="sl-ig sm"><i class="bi bi-person-gear"></i><input id="organizer" class="form-control" maxlength="100" placeholder="المنظم — اختياري"></div></div>
        </div>
      </section>

      <!-- ===== add goods ===== -->
      <section class="sl-card reveal">
        <div class="sl-card-h"><h6><i class="bi bi-upc-scan"></i> <?= $isSale ? 'إضافة الكراتين' : 'الكراتين المرتجعة' ?></h6><span class="sl-hint"><kbd>Enter</kbd> بعد الكود — وكود البالتة بيضيفها كلها</span></div>
        <div class="scan-box">
          <i class="bi bi-upc sb-ic"></i>
          <input id="scan" class="form-control" autocomplete="off" placeholder="امسح باركود الكرتونة أو البالتة">
          <div class="beam"></div><span class="sb-corner c1"></span><span class="sb-corner c2"></span><span class="sb-corner c3"></span><span class="sb-corner c4"></span>
        </div>
        <div id="scanMsg" class="mt-2"></div>
        <?php if ($isSale): ?>
        <button type="button" class="sl-pick-btn" id="btnPick"><span class="pb-ic"><i class="bi bi-boxes"></i></span><span><b>اختيار من المخزن</b><small>بالتات وكراتين وشكاير من غير باركود — بكل تفاصيلها</small></span><i class="bi bi-chevron-down pb-arrow"></i></button>
        <div id="pickPanel" class="sp-panel mt-3 d-none">
          <div class="sp-bar">
            <div class="sp-search"><i class="bi bi-search"></i><input id="pickFilter" class="form-control" placeholder="ابحث بالنمرة أو الصنف أو رقم البالتة أو الكرتونة أو اللوط"></div>
            <div class="sp-chips" id="pickChips">
              <button type="button" data-f="all" class="on">الكل</button><button type="button" data-f="carton"><i class="bi bi-box-seam"></i> كراتين</button><button type="button" data-f="sack"><i class="bi bi-bag"></i> شكاير</button>
              <button type="button" data-f="std">نظامي</button><button type="button" data-f="mixed">متعدد</button><button type="button" data-f="full">بالتات كاملة</button>
            </div>
            <button type="button" class="btn btn-outline-secondary sp-reload" id="pickReload" title="تحديث"><i class="bi bi-arrow-repeat"></i></button>
          </div>
          <div class="sp-sug">
            <div class="sp-sug-h"><i class="bi bi-magic"></i><b>العميل طالب كمية معيّنة؟</b><span>اكتبها والنظام يقترح البالتات والكراتين المناسبة (الأقدم الأول)</span></div>
            <div class="sp-sug-f">
              <div class="input-group"><input id="sugQty" type="number" min="0" step="0.01" class="form-control num" placeholder="مثال: 5"><select id="sugUnit" class="form-select" style="max-width:90px"><option value="1000">طن</option><option value="1">كيلو</option></select></div>
              <select id="sugCount" class="form-select"><option value="">— النمرة —</option></select>
              <button type="button" class="btn sp-sug-go" id="sugGo"><i class="bi bi-stars"></i> اقترح</button>
            </div>
            <label class="sp-sug-opt"><input type="checkbox" id="sugMix" class="form-check-input" checked> كمّل الفرق من <b>المتعدد</b> بتاع نفس النمرة (عشان يطلع مع الشغل)</label>
            <div id="sugBox"></div>
          </div>
          <div class="sp-sum" id="pickSum"></div>
          <div id="pickList" class="sp-list"></div>
        </div>
        <?php endif; ?>
      </section>

      <!-- ===== invoice content ===== -->
      <section class="sl-card p-0 reveal">
        <div class="sl-card-h px-3 pt-3"><h6><i class="bi bi-box-seam"></i> محتوى <?= $isSale ? 'الفاتورة' : 'المرتجع' ?></h6></div>
        <div class="sl-kpis px-3">
          <div><b id="pkgCount">0</b><span>كرتونة / شكارة</span></div>
          <div><b class="num" id="pkgKg">0</b><span>كيلو صافي</span></div>
          <div><b id="palCount">0</b><span>بالتة</span></div>
          <div><b id="itemCount">0</b><span>نمرة</span></div>
        </div>
        <div class="px-3" id="palChips"></div>
        <div class="table-responsive sl-table-wrap">
          <table class="table table-sm table-hover sl-table" id="pkgTable">
            <thead><tr><th>#</th><th>الباركود</th><th>الكود</th><th>النمرة</th><th>كونات</th><th>صافي (كيلو)</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
          <div class="sl-empty" id="pkgEmpty"><i class="bi bi-upc-scan"></i><b>لسه مفيش كراتين</b><span><?= $isSale ? 'امسح باركود أو اختار من المخزن' : 'امسح باركود الكرتونة المرتجعة' ?></span></div>
        </div>
      </section>

      <section class="sl-card reveal">
        <div class="sl-card-h"><h6><i class="bi bi-list-ul"></i> أصناف من غير باركود <small class="text-muted">(عوادم، كونات، ...)</small></h6><button class="btn btn-sm btn-outline-primary" id="addManual"><i class="bi bi-plus-lg"></i> صنف</button></div>
        <table class="table table-sm sl-table mb-0" id="manualTable"><thead><tr><th style="width:50%">الصنف</th><th>الكمية</th><th class="price-only">السعر</th><th class="price-only">الإجمالي</th><th></th></tr></thead><tbody></tbody></table>
      </section>
    </div>

    <!-- ===== summary ===== -->
    <aside class="sl-side">
      <div class="sl-sum reveal">
        <div class="sl-sum-h"><span><i class="bi bi-calculator"></i> ملخص <?= $isSale ? 'الفاتورة' : 'المرتجع' ?></span><b id="sumCustomer">— لسه ما اخترتش عميل —</b></div>
        <div class="price-only">
          <div class="sl-sub mt-0"><i class="bi bi-tag"></i> الأسعار حسب النمرة</div>
          <table class="table table-sm sl-price" id="priceTable"><thead><tr><th>النمرة</th><th>كيلو</th><th style="width:34%">سعر الكيلو</th><th>الإجمالي</th></tr></thead><tbody></tbody></table>
          <div class="sl-line"><span>الإجمالي</span><b class="num" id="subtotal">0.00</b></div>
          <div class="sl-ig sm mb-2"><i class="bi bi-tag"></i><input id="discount" type="number" step="0.01" min="0" class="form-control num" value="0" placeholder="خصم"></div>
          <div class="sl-total"><span>الصافي</span><div class="total-big" id="total">0.00</div><small>جنيه</small></div>
          <div class="input-group mb-2 sl-pay"><span class="input-group-text"><?= $isSale ? 'مدفوع دلوقتي' : 'اترجع للعميل' ?></span><input id="paid" type="number" step="0.01" min="0" class="form-control num" value="0">
            <select id="payMethod" class="form-select" style="max-width:110px"><?php foreach (PAY_METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="sl-line rest"><span><?= $isSale ? 'الباقي على العميل' : 'الباقي للعميل' ?></span><b class="num" id="restAmt">0.00</b></div>
        </div>
        <div class="delivery-only d-none"><div class="sl-total del"><span>إجمالي الوزن</span><div class="total-big"><span id="kgBig">0.000</span></div><small>كيلو</small></div></div>
        <div class="sl-ig sm my-2"><i class="bi bi-chat-left-text"></i><input id="notes" class="form-control" placeholder="ملاحظات"></div>
        <button class="sl-save" id="btnSave" disabled><i class="bi bi-check2-circle"></i> حفظ <?= $isSale ? 'الفاتورة' : 'المرتجع' ?></button>
        <div class="sl-why" id="saveWhy"></div>
        <div id="saveMsg" class="mt-2"></div>
      </div>
    </aside>
  </div>
</div>
<template id="manualTpl"><tr>
  <td><select class="form-select form-select-sm m-item"><option value="">— اختر —</option><?php foreach ($manualItems as $i): ?><option value="<?= $i['id'] ?>"><?= e($i['code'] . ' — ' . $i['name']) ?> (<?= UNITS[$i['unit']] ?>)</option><?php endforeach; ?></select></td>
  <td><input type="number" step="0.001" min="0" class="form-control form-control-sm num m-qty"></td>
  <td class="price-only"><input type="number" step="0.01" min="0" class="form-control form-control-sm num m-price"></td>
  <td class="num m-amount price-only">0.00</td><td><button class="btn btn-sm btn-outline-danger m-del"><i class="bi bi-x"></i></button></td>
</tr></template>
<?php require __DIR__ . '/inc/footer.php';
