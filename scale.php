<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('scale');

$finished = items_by_type('finished');
$raws = items_by_type('raw');
$cones = items_by_type('cone');
$packingTypes = q('SELECT * FROM packing_types WHERE active=1 ORDER BY id')->fetchAll();
$recent = q("SELECT p.id, p.serial, p.net, p.gross, p.cones_count, p.status, p.created_at, i.code, i.name
             FROM packages p JOIN items i ON i.id=p.finished_item_id
             WHERE DATE(p.created_at)=CURDATE() ORDER BY p.id DESC LIMIT 15")->fetchAll();
$todayTotal = q("SELECT COUNT(*) n, COALESCE(SUM(net),0) net FROM packages WHERE status<>'void' AND DATE(created_at)=CURDATE()")->fetch();

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
     data-csrf="<?= csrf_token() ?>" data-baud="<?= e(setting('scale_baud', '9600')) ?>">
  <div class="col-xl-5">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">الوزن القائم</h5>
        <div>
          <span id="scaleStatus" class="badge bg-secondary">الميزان غير متصل</span>
          <button id="btnConnect" class="btn btn-sm btn-outline-primary">توصيل الميزان</button>
        </div>
      </div>
      <div id="display" class="scale-display offline">0.00</div>
      <div class="input-group mt-2">
        <span class="input-group-text">وزن يدوي</span>
        <input id="manualGross" type="number" step="0.01" min="0" class="form-control num" placeholder="لو الميزان مش متصل اكتب الوزن هنا">
        <span class="input-group-text">kg</span>
      </div>
      <div class="d-flex justify-content-between small text-muted mt-1"><span id="lastAt"></span><span id="rawFrame" dir="ltr"></span></div>
      <button id="btnSameWeight" class="btn btn-sm btn-outline-warning mt-1 d-none">الكرتونة الجديدة نفس الوزن بالظبط — استخدم الوزن ده</button>
      <hr>
      <div class="row text-center">
        <div class="col"><div class="text-muted">الفوارغ</div><div class="fs-4 num" id="tare">0.000</div></div>
        <div class="col"><div class="text-muted">الصافي</div><div class="big-net text-success" id="net">0.000</div></div>
      </div>
      <button id="btnSave" class="btn btn-success btn-xl w-100 mt-3" disabled>حفظ وطباعة الستيكر</button>
      <div id="msg" class="mt-2"></div>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card p-3 mb-3">
      <div class="row g-2">
        <div class="col-md-8"><label class="form-label">النمرة (الخيط التام)</label><select id="finished" class="form-select form-select-lg"><option value="">— اختر —</option><?= opt($finished) ?></select></div>
        <div class="col-md-4"><label class="form-label">البوي (الخام)</label><select id="raw" class="form-select"><option value="">—</option><?= opt($raws) ?></select></div>
        <div class="col-md-5"><label class="form-label">نوع التعبئة</label><select id="packing" class="form-select"><option value="">— اختر —</option>
          <?php foreach ($packingTypes as $t): ?><option value="<?= $t['id'] ?>" data-cone="<?= e((string)$t['cone_weight']) ?>" data-pack="<?= e((string)$t['pack_weight']) ?>" data-bag="<?= e((string)$t['bag_weight']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label">لون الكونة</label><select id="cone" class="form-select"><option value="">بدون</option><?= opt($cones) ?></select></div>
        <div class="col-md-2"><label class="form-label">عدد الكونات</label><input id="conesCount" type="number" min="0" class="form-control num" value="<?= e(setting('default_cones', '6')) ?>"></div>
      </div>
      <div class="small text-muted mt-2" id="tareDetail"></div>
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
<iframe id="printFrame" style="position:absolute;width:0;height:0;border:0;left:-9999px"></iframe>
<?php require __DIR__ . '/inc/footer.php';
