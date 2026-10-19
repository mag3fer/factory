<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('scale');

$canEditPacking = $u['role'] !== 'scale';   // the scale operator can't edit/remove packing types or tune the sound
$raws = items_by_type('raw');
// a raw that's used up is deactivated: hide it and the counts made only from it
$activeRaw = array_column($raws, 'id', 'id');
$finished = array_values(array_filter(items_by_type('finished'), fn($i) => !$i['raw_item_id'] || isset($activeRaw[$i['raw_item_id']])));
$cones = items_by_type('cone');
$allPackingTypes = q('SELECT id, name, pack_item_id, cone_weight, pack_weight, bag_weight, active FROM packing_types ORDER BY id')->fetchAll();
$packingTypes = array_values(array_filter($allPackingTypes, fn($t) => $t['active']));
$packItems = q("SELECT id, code, name FROM items WHERE type='pack' AND active=1 ORDER BY code")->fetchAll();
$recent = q("SELECT p.id, p.serial, p.net, p.gross, p.cones_count, p.status, p.created_at, i.code, i.name
             FROM packages p JOIN items i ON i.id=p.finished_item_id
             WHERE DATE(p.created_at)=CURDATE() ORDER BY p.id DESC LIMIT 15")->fetchAll();
require __DIR__ . '/inc/scale_stats.php';
$stats = scale_stats();
$todayTotal = ['n' => $stats['total']['n'], 'net' => $stats['total']['net']];
$usual = scale_usual_per_cone();
$openPallets = open_pallets();
$palletSize = max(1, (int)setting('pallet_size', '20'));

function opt(array $items, bool $withTare = false): string
{
    $h = '';
    foreach ($items as $i) {
        $h .= '<option value="' . $i['id'] . '"' . ($withTare ? ' data-tare="' . e((string)$i['tare_weight']) . '"' : '') . '>' . e($i['code'] . ' — ' . $i['name']) . '</option>';
    }
    return $h;
}
$pageTitle = 'الميزان';
$pageScripts = ['assets/scale.js?v=' . filemtime(__DIR__ . '/assets/scale.js')];
require __DIR__ . '/inc/header.php';
?>
<div class="row g-3" id="station"
     data-csrf="<?= csrf_token() ?>" data-baud="<?= e(setting('scale_baud', '9600')) ?>"
     data-target-per-cone="<?= e(setting('target_gross_per_cone', '6')) ?>" data-target-ok="<?= e(setting('target_ok_pct', '3')) ?>" data-target-warn="<?= e(setting('target_warn_pct', '6')) ?>"
     data-std-cones="<?= (int)setting('default_cones', '6') ?>"
     data-pallets="<?= e(json_encode((object)$openPallets)) ?>" data-pallet-size="<?= $palletSize ?>"
     data-usual="<?= e(json_encode((object)$usual)) ?>">
  <div class="col-xl-5">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">الوزن القائم</h5>
        <div>
          <span id="scaleStatus" class="badge bg-secondary">الميزان غير متصل</span>
          <button id="btnConnect" class="btn btn-sm btn-outline-primary">توصيل الميزان</button>
        </div>
      </div>
      <div class="scale-device">
        <div class="sd-top"><span class="sd-brand">ANHENG <b>ETA-AC</b></span>
          <span class="sd-leds"><i id="ledStable" class="led">STABLE</i><i id="ledZero" class="led">ZERO</i><i id="ledSaved" class="led led-red">SAVED</i></span></div>
        <div class="sd-lcd"><div class="sd-ghost" aria-hidden="true">888.88</div><div id="display" class="scale-display offline">0.00</div><span class="sd-unit">kg</span></div>
        <div class="sd-plate-wrap"><div class="sd-plate" id="plate"></div><div class="sd-base"></div></div>
      </div>
      <div class="input-group mt-2">
        <span class="input-group-text">وزن يدوي</span>
        <input id="manualGross" type="number" step="0.01" min="0" class="form-control num" placeholder="لو الميزان مش متصل اكتب الوزن هنا">
        <span class="input-group-text">kg</span>
      </div>
      <div id="target" class="target-bar mt-2 d-none"><div class="tb-text"><span id="tbLabel"></span><b id="tbDiff" dir="ltr"></b></div><div class="tb-track"><i class="tb-mid"></i><i class="tb-dot" id="tbDot"></i></div></div>
      <div class="d-flex justify-content-between small text-muted mt-1"><span id="lastAt"></span><span id="rawFrame" dir="ltr"></span></div>
      <button id="btnSameWeight" class="btn btn-sm btn-outline-warning mt-1 d-none">الكرتونة الجديدة نفس الوزن بالظبط — استخدم الوزن ده</button>
      <hr>
      <div class="row text-center">
        <div class="col"><div class="text-muted">الفوارغ</div><div class="fs-4 num" id="tare">0.000</div></div>
        <div class="col"><div class="text-muted">الصافي</div><div class="big-net text-success" id="net">0.000</div></div>
      </div>
      <button id="btnSave" class="btn btn-success btn-xl w-100 mt-3" disabled>حفظ وطباعة الستيكر</button>
      <div id="conesWarn" class="mt-2"></div>
      <div id="warn" class="mt-2"></div>
      <div id="msg" class="mt-2"></div>
      <?php if ($canEditPacking): ?><div class="d-flex align-items-center gap-2 mt-2 small text-muted"><span>🔊 مستوى الصوت</span><input type="range" id="beepVol" min="0" max="1" step="0.05" class="form-range flex-grow-1" style="max-width:220px"><button type="button" id="beepTest" class="btn btn-sm btn-outline-secondary py-0">جرّب</button></div><?php endif; ?>
      <div class="small text-muted mt-2" dir="rtl">اختصارات: <kbd>Enter</kbd> حفظ وطباعة — <kbd>F2</kbd> نفس الوزن — <kbd>F4</kbd> طباعة آخر كرتونة — <kbd>↑</kbd>/<kbd>↓</kbd> عدد الكونات</div>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card p-3 mb-3">
      <div class="row g-2">
        <div class="col-md-8"><label class="form-label">النمرة (الخيط التام)</label><select id="finished" class="form-select form-select-lg"><option value="">— اختر —</option><?php foreach ($finished as $i): ?><option value="<?= $i['id'] ?>" data-raw="<?= (int)$i['raw_item_id'] ?>" data-spec="<?= e(spec_key($i['spec'])) ?>"><?= e($i['code'] . ' — ' . $i['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">البوي (الخام اللي على المكنة)</label><select id="raw" class="form-select"><option value="">كل الخامات</option><?php foreach ($raws as $i): ?><option value="<?= $i['id'] ?>" data-spec="<?= e(spec_key($i['spec'])) ?>"><?= e($i['code'] . ' — ' . $i['name'] . ($i['spec'] ? ' ← ' . $i['spec'] : '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label d-flex justify-content-between">نوع التعبئة
            <?php if ($canEditPacking): ?><a href="#" id="btnEditPacking" class="small">تعديل الأوزان</a><?php endif; ?></label><select id="packing" class="form-select"><option value="">— اختر —</option>
          <?php foreach ($packingTypes as $t): ?><option value="<?= $t['id'] ?>" data-cone="<?= e((string)$t['cone_weight']) ?>" data-pack="<?= e((string)$t['pack_weight']) ?>" data-bag="<?= e((string)$t['bag_weight']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label">لون الكونة</label><select id="cone" class="form-select"><option value="">بدون</option><?= opt($cones, true) ?></select></div>
        <div class="col-md-2"><label class="form-label">عدد الكونات</label><input id="conesCount" type="number" min="0" class="form-control num" value="<?= e(setting('default_cones', '6')) ?>"></div>
      </div>
      <div id="palletInfo" class="pallet-info mt-2 d-none"></div>
      <div class="small text-muted mt-2" id="tareDetail"></div>
    </div>
    <div class="card p-3 mb-3">
      <div class="d-flex justify-content-between"><h6>ملخص النهارده</h6><span class="text-muted small">آخر ساعة: <b id="hourN"><?= (int)$stats['hour']['n'] ?></b> كرتونة — <b class="num" id="hourNet"><?= fmt_qty($stats['hour']['net'], 1) ?></b> كيلو</span></div>
      <table class="table table-sm mb-0" id="summary">
        <thead><tr><th>النمرة</th><th>كراتين</th><th>كونات</th><th>صافي (كيلو)</th></tr></thead>
        <tbody>
        <?php foreach ($stats['items'] as $r): ?>
          <tr><td><?= e($r['code']) ?></td><td><?= (int)$r['n'] ?></td><td><?= (int)$r['cones'] ?></td><td class="num fw-bold"><?= fmt_qty($r['net'], 1) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$stats['items']): ?><tr><td colspan="4" class="text-muted text-center">لسه مفيش كراتين النهارده</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card p-3">
      <div class="d-flex justify-content-between"><h6>آخر الكراتين النهارده</h6><span class="text-muted">الإجمالي: <b class="num" id="todayNet"><?= fmt_qty($todayTotal['net'], 1) ?></b> كيلو — <b id="todayN"><?= (int)$todayTotal['n'] ?></b> كرتونة</span></div>
      <table class="table table-sm mb-0" id="recent">
        <thead><tr><th>الباركود</th><th>النمرة</th><th>كونات</th><th>قائم</th><th>صافي</th><th>الوقت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr class="<?= $r['status'] === 'void' ? 'text-decoration-line-through text-muted' : '' ?>"><td class="num"><?= e($r['serial']) ?></td><td><?= e($r['code']) ?></td><td><?= (int)$r['cones_count'] ?></td>
            <td class="num"><?= fmt_qty($r['gross'], 2) ?></td><td class="num fw-bold"><?= fmt_qty($r['net'], 2) ?></td><td><?= e(substr($r['created_at'], 11, 5)) ?></td>
            <td><button class="btn btn-sm btn-outline-secondary reprint" data-id="<?= $r['id'] ?>">طباعة</button></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php if ($canEditPacking): ?>
<div class="modal fade" id="packingModal" tabindex="-1">
  <div class="modal-dialog modal-xl"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">أنواع التعبئة — أوزان الفوارغ (كيلو)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="small text-muted mb-2">الفوارغ = عدد الكونات × وزن الكونة (بيتاخد من لون الكونة المختارة، وبيتعدّل من «الأصناف») + وزن الكرتونة/الشكارة + عدد الكونات × وزن الكيس. التعديل مش بيغيّر الكراتين اللي اتسجلت قبل كده.</div>
      <table class="table table-sm align-middle" id="ptTable">
        <thead><tr><th>الاسم</th><th>الكرتونة/الشكارة (مخزن)</th><th class="d-none">وزن الكونة</th><th>وزن الكرتونة/الشكارة</th><th>وزن الكيس (على كل كونة)</th><th>نشط</th></tr></thead>
        <tbody></tbody>
      </table>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="ptAdd">+ نوع جديد</button>
    </div>
    <div class="modal-footer">
      <input type="password" id="ptAdminPass" class="form-control w-auto" placeholder="باسورد المدير" autocomplete="off">
      <button type="button" class="btn btn-success" id="ptSave">حفظ</button>
      <div id="ptMsg" class="w-100"></div>
    </div>
  </div></div>
</div>
<script type="application/json" id="ptData"><?= json_encode(['types' => $allPackingTypes, 'packs' => $packItems], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>
<iframe id="printFrame" style="position:absolute;width:0;height:0;border:0;left:-9999px"></iframe>
<?php require __DIR__ . '/inc/footer.php';
