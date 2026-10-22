<?php
// Pallets: every pallet with what it is (count, carton / sack, نظامي / متعدد), how full it got, what is left in the warehouse
// and who bought from it. Cards grouped by count (or a compact table), filtered instantly on the page.
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('store', 'scale');

$rows = q("SELECT pl.*, f.code, f.name, f.spec, f.lot_no, pt.name ptname,
                  COALESCE(x.cnt,0) cnt, COALESCE(x.in_stock,0) in_stock, COALESCE(x.sold,0) sold,
                  COALESCE(x.net,0) net, COALESCE(x.net_stock,0) net_stock, COALESCE(x.net_sold,0) net_sold,
                  COALESCE(x.cones_stock,0) cones_stock, x.first_at, x.last_at,
                  (SELECT GROUP_CONCAT(DISTINCT pa.name ORDER BY pa.name SEPARATOR '، ')
                     FROM sales_packages sp JOIN sales_invoices s ON s.id=sp.invoice_id AND s.type='sale' AND s.voided=0
                     JOIN parties pa ON pa.id=s.customer_id JOIN packages pk ON pk.id=sp.package_id
                    WHERE pk.pallet_id=pl.id AND pk.status='sold') customers
           FROM pallets pl JOIN items f ON f.id=pl.finished_item_id LEFT JOIN packing_types pt ON pt.id=pl.packing_type_id
           LEFT JOIN (SELECT pallet_id, COUNT(*) cnt, SUM(status='in_stock') in_stock, SUM(status='sold') sold, SUM(net) net,
                             SUM(IF(status='in_stock', net, 0)) net_stock, SUM(IF(status='sold', net, 0)) net_sold,
                             SUM(IF(status='in_stock', cones_count, 0)) cones_stock, MIN(created_at) first_at, MAX(created_at) last_at
                      FROM packages WHERE status<>'void' AND pallet_id IS NOT NULL GROUP BY pallet_id) x ON x.pallet_id=pl.id
           ORDER BY f.code, pl.id DESC LIMIT 500")->fetchAll();

$tot = ['pallets' => 0, 'cartons' => 0, 'sacks' => 0, 'kg' => 0.0, 'full' => 0, 'filling' => 0, 'partial' => 0, 'mixed' => 0, 'soldout' => 0, 'std' => 0, 'std_kg' => 0.0, 'mix_kg' => 0.0];
foreach ($rows as &$r) {
    $r['sack'] = str_starts_with((string)$r['ptname'], 'شكارة');
    $cnt = (int)$r['cnt']; $stock = (int)$r['in_stock']; $sold = (int)$r['sold'];
    $r['st'] = $cnt === 0 ? 'empty' : ($stock === 0 ? 'soldout' : ($sold > 0 ? 'partial' : 'stock'));
    $r['fill'] = $r['full'] ? 'full' : ($r['closed'] ? 'closed' : 'filling');
    if ($stock > 0) {
        $tot['pallets']++; $tot['kg'] += (float)$r['net_stock'];
        $r['sack'] ? $tot['sacks'] += $stock : $tot['cartons'] += $stock;
        if ($r['fill'] === 'filling') $tot['filling']++;
        if ($r['st'] === 'partial') $tot['partial']++;
        if ($r['st'] === 'stock' && $r['full']) $tot['full']++;
        if ($r['is_mixed']) { $tot['mixed']++; $tot['mix_kg'] += (float)$r['net_stock']; }
        elseif (!$r['sack']) { $tot['std']++; $tot['std_kg'] += (float)$r['net_stock']; $tot['std_n'] = ($tot['std_n'] ?? 0) + $stock; }   // نظامي = cartons of fixed cones, not sacks
    }
    if ($r['st'] === 'soldout') $tot['soldout']++;
}
unset($r);
$groups = [];
foreach ($rows as $r) $groups[$r['code']][] = $r;

// ?detail=<kpi> — what is behind a number in the header (opened in a window on the page)
const PP_DETAIL = [
    'pallets' => ['كل البالتات اللي في المخزن', null], 'cartons' => ['الكراتين اللي في المخزن', null], 'sacks' => ['الشكاير اللي في المخزن', null],
    'kg' => ['الصافي اللي في المخزن', null], 'full' => ['البالتات الكاملة المقفولة', null], 'filling' => ['البالتات اللي بتتملي', null],
    'partial' => ['بالتات اتباع منها جزء', null], 'std' => ['كراتين النظامي (6 كونات)', null], 'mixed' => ['المتعدد', null],
];
if (isset($_GET['detail'])) {
    $k = (string)$_GET['detail'];
    if (!isset(PP_DETAIL[$k])) { http_response_code(404); exit; }
    $keep = fn($r) => (int)$r['in_stock'] > 0 && match ($k) {
        'cartons' => !$r['sack'], 'sacks' => (bool)$r['sack'], 'full' => $r['st'] === 'stock' && $r['full'], 'filling' => $r['fill'] === 'filling',
        'partial' => $r['st'] === 'partial', 'std' => !$r['sack'] && !$r['is_mixed'], 'mixed' => (bool)$r['is_mixed'], default => true,
    };
    $list = array_values(array_filter($rows, $keep));
    $by = [];   // per count + packing + type
    foreach ($list as $r) {
        $key = $r['code'] . '|' . (int)$r['sack'] . '|' . (int)$r['is_mixed'];
        $by[$key] ??= ['code' => $r['code'], 'spec' => $r['spec'], 'name' => $r['name'], 'sack' => $r['sack'], 'mixed' => (int)$r['is_mixed'], 'pallets' => 0, 'n' => 0, 'cones' => 0, 'kg' => 0.0];
        $by[$key]['pallets']++; $by[$key]['n'] += (int)$r['in_stock']; $by[$key]['cones'] += (int)$r['cones_stock']; $by[$key]['kg'] += (float)$r['net_stock'];
    }
    usort($by, fn($a, $b) => $b['kg'] <=> $a['kg']);
    $sum = ['n' => array_sum(array_column($by, 'n')), 'kg' => array_sum(array_column($by, 'kg')), 'cones' => array_sum(array_column($by, 'cones'))];
    $tag = fn($r) => '<em class="' . ($r['sack'] ? 't-sack' : 't-ctn') . '">' . ($r['sack'] ? 'شكارة' : 'كرتونة') . '</em>' . (!empty($r['mixed']) || !empty($r['is_mixed']) ? '<em class="t-mix">متعدد</em>' : '<em class="t-std">نظامي</em>');
    ob_start(); ?>
    <div class="dd-stats"><div><b><?= count($list) ?></b><span>بالتة</span></div><div><b><?= $sum['n'] ?></b><span>كرتونة / شكارة</span></div>
      <div><b><?= fmt_qty($sum['kg'], 1) ?></b><span>كيلو صافي</span></div><div><b><?= $sum['cones'] ?></b><span>كونة</span></div>
      <div><b><?= $sum['n'] ? fmt_qty($sum['kg'] / $sum['n'], 2) : '—' ?></b><span>متوسط الوحدة (كجم)</span></div></div>
    <h6 class="dd-h">حسب النمرة والنوع</h6>
    <table class="table table-sm dd-t"><thead><tr><th>النمرة</th><th>النوع</th><th>بالتات</th><th>العدد</th><th>كونات</th><th>كيلو صافي</th><th>النسبة</th></tr></thead>
    <?php foreach ($by as $g): ?><tr><td><b><?= e($g['code']) ?></b> <?= e($g['spec']) ?><small><?= e($g['name']) ?></small></td><td><?= $tag($g) ?></td><td><?= $g['pallets'] ?></td><td class="fw-bold"><?= $g['n'] ?></td><td><?= $g['cones'] ?></td>
      <td class="num fw-bold"><?= fmt_qty($g['kg'], 1) ?></td><td class="num"><?= $sum['kg'] > 0 ? round($g['kg'] / $sum['kg'] * 100) : 0 ?>%</td></tr><?php endforeach; ?>
    </table>
    <h6 class="dd-h">البالتات</h6>
    <table class="table table-sm dd-t"><thead><tr><th>البالتة</th><th>النمرة</th><th>النوع</th><th>في المخزن</th><th>اتباع</th><th>كونات</th><th>كيلو صافي</th><th>الإنتاج</th></tr></thead>
    <?php foreach ($list as $r): ?><tr><td><a href="pallet_view.php?id=<?= (int)$r['id'] ?>" dir="ltr"><b><?= e($r['serial']) ?></b></a></td><td><?= e($r['code']) ?></td><td><?= $tag($r) ?></td>
      <td class="fw-bold"><?= (int)$r['in_stock'] ?> / <?= (int)$r['capacity'] ?></td><td><?= (int)$r['sold'] ?: '—' ?></td><td><?= (int)$r['cones_stock'] ?></td>
      <td class="num fw-bold"><?= fmt_qty((float)$r['net_stock'], 1) ?></td><td class="num small"><?= e(date('d/m', strtotime((string)($r['first_at'] ?? $r['created_at'])))) ?><?= $r['last_at'] && substr($r['last_at'], 0, 10) !== substr((string)$r['first_at'], 0, 10) ? ' — ' . e(date('d/m', strtotime($r['last_at']))) : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="8" class="text-muted text-center">مفيش</td></tr><?php endif; ?>
    </table>
    <?php
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['title' => PP_DETAIL[$k][0], 'html' => ob_get_clean()], JSON_UNESCAPED_UNICODE);
    exit;
}

$pageTitle = 'البالتات';
require __DIR__ . '/inc/header.php';
$dm = fn(?string $t) => $t ? date('d/m', strtotime($t)) : '';
?>
<link rel="stylesheet" href="assets/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/dashboard.css') ?>">
<link rel="stylesheet" href="assets/pallets.css?v=<?= filemtime(__DIR__ . '/assets/pallets.css') ?>">
<div class="pp">
  <section class="pp-hero reveal">
    <div class="pp-hero-bg"><i></i><i></i></div>
    <div class="pp-hero-t"><div class="pp-hero-ic"><i class="bi bi-boxes"></i></div>
      <div><h3>البالتات</h3><div class="pp-hero-sub">كرتونة <?= (int)setting('pallet_size', '20') ?> / شكارة 15 من نفس النمرة والنوع — السعة بتتغيّر من «تعديل الأوزان» في الميزان</div></div></div>
    <div class="pp-kpis">
      <div data-k="pallets"><b data-count="<?= $tot['pallets'] ?>">0</b><span>بالتة في المخزن</span></div>
      <div data-k="cartons"><b data-count="<?= $tot['cartons'] ?>">0</b><span>كرتونة</span></div>
      <div data-k="sacks"><b data-count="<?= $tot['sacks'] ?>">0</b><span>شكارة</span></div>
      <div data-k="kg"><b data-count="<?= round($tot['kg'] / 1000, 2) ?>" data-dec="2">0</b><span>طن صافي</span></div>
      <div class="sm" data-k="full"><b><?= $tot['full'] ?></b><span>كاملة مقفولة</span></div>
      <div class="sm" data-k="filling"><b><?= $tot['filling'] ?></b><span>بتتملي</span></div>
      <div class="sm" data-k="partial"><b><?= $tot['partial'] ?></b><span>اتباع منها جزء</span></div>
      <div class="k-std" data-k="std"><b><?= $tot['std'] ?></b><span>بالتة كراتين نظامي · <?= (int)($tot['std_n'] ?? 0) ?> كرتونة · <?= fmt_qty($tot['std_kg'], 0) ?> كجم</span></div>
      <div class="k-mix" data-k="mixed"><b><?= $tot['mixed'] ?></b><span>بالتة متعدد · <?= fmt_qty($tot['mix_kg'], 0) ?> كجم</span></div>
    </div>
  </section>

  <section class="pp-bar reveal no-print">
    <div class="pp-search"><i class="bi bi-search"></i><input id="ppQ" class="form-control" placeholder="ابحث برقم البالتة أو النمرة أو الصنف أو العميل"></div>
    <div class="pp-chips" id="ppState">
      <button data-v="stock" class="on">في المخزن</button><button data-v="full">كاملة</button><button data-v="filling">بتتملي</button>
      <button data-v="partial">اتباع منها جزء</button><button data-v="soldout">اتباعت بالكامل</button><button data-v="all">الكل</button>
    </div>
    <div class="pp-chips" id="ppKind"><button data-v="" class="on">الكل</button><button data-v="ctn"><i class="bi bi-box-seam"></i> كراتين</button><button data-v="sack"><i class="bi bi-bag"></i> شكاير</button><button data-v="std">نظامي</button><button data-v="mix">متعدد</button></div>
    <div class="pp-view" id="ppView"><button data-v="cards" class="on" title="كروت"><i class="bi bi-grid-3x3-gap"></i></button><button data-v="table" title="جدول"><i class="bi bi-list-ul"></i></button></div>
  </section>
  <div class="pp-count" id="ppCount"></div>

  <div id="ppCards">
  <?php foreach ($groups as $code => $list): $g0 = $list[0]; ?>
    <section class="pp-grp" data-code="<?= e($code) ?>">
      <div class="pp-grp-h"><b class="num"><?= e($code) ?></b><span><?= e($g0['spec']) ?></span><small><?= e($g0['name']) ?></small><em class="pp-grp-sum"></em></div>
      <div class="pp-grid">
      <?php foreach ($list as $r):
          $cnt = (int)$r['cnt']; $stock = (int)$r['in_stock']; $sold = (int)$r['sold']; $cap = max(1, (int)$r['capacity']);
          $u1 = $r['sack'] ? 'شكارة' : 'كرتونة';
          $stLbl = ['empty' => 'فاضية', 'soldout' => 'اتباعت بالكامل', 'partial' => 'اتباع منها ' . $sold, 'stock' => 'كلها في المخزن'][$r['st']];
          $fillLbl = ['full' => 'مكتملة', 'closed' => 'اتقفلت ناقصة', 'filling' => 'بتتملي'][$r['fill']];
          $search = mb_strtolower($r['serial'] . ' ' . $r['code'] . ' ' . $r['name'] . ' ' . $r['spec'] . ' ' . $r['customers']); ?>
        <a class="pc st-<?= $r['st'] ?> fl-<?= $r['fill'] ?> <?= $r['sack'] ? 'is-sack' : 'is-ctn' ?><?= $r['is_mixed'] ? ' is-mix' : '' ?>" href="pallet_view.php?id=<?= $r['id'] ?>"
           data-st="<?= $r['st'] ?>" data-fill="<?= $r['fill'] ?>" data-sack="<?= (int)$r['sack'] ?>" data-mixed="<?= (int)$r['is_mixed'] ?>" data-kg="<?= round((float)$r['net_stock'], 2) ?>" data-left="<?= $stock ?>" data-q="<?= e($search) ?>">
          <div class="pc-top">
            <div><div class="pc-serial" dir="ltr"><?= e($r['serial']) ?></div>
              <div class="pc-tags"><span class="t-pk"><?= $u1 ?></span><span class="<?= $r['is_mixed'] ? 't-mix' : 't-std' ?>"><?= $r['is_mixed'] ? 'متعدد' : 'نظامي' ?></span><span class="t-fl"><?= $fillLbl ?></span></div></div>
            <div class="pc-ring" title="<?= $cnt ?> من <?= $cap ?>"><svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="15.5" class="trk"/><circle cx="18" cy="18" r="15.5" class="bar" style="--p:<?= min(100, round($cnt / $cap * 100)) ?>"/></svg><b><?= $cnt ?><small>/<?= $cap ?></small></b></div>
          </div>
          <div class="pc-stack" title="في المخزن <?= $stock ?> — اتباع <?= $sold ?>"><i class="in" style="--w:<?= round($stock / $cap * 100) ?>%"></i><i class="out" style="--w:<?= round($sold / $cap * 100) ?>%"></i></div>
          <div class="pc-st"><span class="dot"></span><?= e($stLbl) ?></div>
          <div class="pc-nums">
            <div><b><?= $stock ?></b><span><?= $u1 ?> في المخزن</span></div>
            <div><b class="num"><?= fmt_qty((float)$r['net_stock'], 1) ?></b><span>كيلو صافي</span></div>
            <div><b><?= (int)$r['cones_stock'] ?></b><span>كونة</span></div>
          </div>
          <div class="pc-foot"><span><i class="bi bi-calendar3"></i> <?= e($dm($r['first_at'] ?? $r['created_at'])) ?><?= $r['last_at'] && $dm($r['last_at']) !== $dm($r['first_at']) ? ' — ' . e($dm($r['last_at'])) : '' ?></span>
            <?php if ($r['customers']): ?><span class="pc-cust" title="<?= e($r['customers']) ?>"><i class="bi bi-person-check"></i> <?= e($r['customers']) ?></span><?php endif; ?></div>
        </a>
      <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
  </div>

  <div id="ppTable" class="pp-table d-none">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead><tr><th>البالتة</th><th>النمرة</th><th>النوع</th><th>الاكتمال</th><th>الحالة</th><th>اتملت</th><th>اتباع</th><th>في المخزن</th><th>صافي متبقي</th><th>اتباعت لـ</th><th>التاريخ</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $stock = (int)$r['in_stock']; ?>
        <tr class="pr st-<?= $r['st'] ?>" data-st="<?= $r['st'] ?>" data-fill="<?= $r['fill'] ?>" data-sack="<?= (int)$r['sack'] ?>" data-mixed="<?= (int)$r['is_mixed'] ?>" data-kg="<?= round((float)$r['net_stock'], 2) ?>" data-left="<?= $stock ?>"
            data-q="<?= e(mb_strtolower($r['serial'] . ' ' . $r['code'] . ' ' . $r['name'] . ' ' . $r['spec'] . ' ' . $r['customers'])) ?>" onclick="location.href='pallet_view.php?id=<?= $r['id'] ?>'">
          <td class="num fw-bold"><a href="pallet_view.php?id=<?= $r['id'] ?>"><?= e($r['serial']) ?></a></td>
          <td><b><?= e($r['code']) ?></b> <small class="text-muted"><?= e($r['spec']) ?></small></td>
          <td><span class="tg <?= $r['sack'] ? 'sack' : 'ctn' ?>"><?= $r['sack'] ? 'شكارة' : 'كرتونة' ?></span><?= $r['is_mixed'] ? ' <span class="tg mix">متعدد</span>' : '' ?></td>
          <td><?= ['full' => 'مكتملة', 'closed' => 'اتقفلت ناقصة', 'filling' => 'بتتملي'][$r['fill']] ?></td>
          <td><span class="tg s-<?= $r['st'] ?>"><?= ['empty' => 'فاضية', 'soldout' => 'اتباعت بالكامل', 'partial' => 'اتباع جزء', 'stock' => 'في المخزن'][$r['st']] ?></span></td>
          <td><?= (int)$r['cnt'] ?> / <?= (int)$r['capacity'] ?></td><td><?= (int)$r['sold'] ?></td>
          <td class="fw-bold"><?= $stock ?> <small class="text-muted"><?= $stock ? '(' . (int)$r['cones_stock'] . ' كونة)' : '' ?></small></td>
          <td class="num fw-bold"><?= fmt_qty((float)$r['net_stock'], 2) ?></td>
          <td class="small"><?= e($r['customers'] ?? '') ?: '—' ?></td>
          <td class="small num"><?= e(substr((string)($r['last_at'] ?? $r['created_at']), 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="modal fade" id="ppModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content dd-modal">
    <div class="modal-header"><h5 class="modal-title" id="ppmTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="ppmBody"></div></div></div></div>
  <div class="pp-empty d-none" id="ppEmpty"><i class="bi bi-inbox"></i><b>مفيش بالتات بالفلتر ده</b></div>
</div>
<script>
(() => {
  const $ = id => document.getElementById(id);
  let st = 'stock', kind = '', view = 'cards';
  try { view = localStorage.getItem('ppView') || 'cards'; } catch (e) {}
  const okKind = el => !kind || (kind === 'ctn' ? el.dataset.sack === '0' : kind === 'sack' ? el.dataset.sack === '1' : kind === 'std' ? el.dataset.mixed === '0' : el.dataset.mixed === '1');
  const okSt = el => st === 'all' ? true : st === 'stock' ? +el.dataset.left > 0 : st === 'full' ? el.dataset.fill === 'full' && el.dataset.st === 'stock'
    : st === 'filling' ? el.dataset.fill === 'filling' && +el.dataset.left > 0 : el.dataset.st === st;
  function apply() {
    const q = $('ppQ').value.trim().toLowerCase();
    let n = 0, kg = 0, left = 0;
    document.querySelectorAll('.pc').forEach(el => { const ok = okSt(el) && okKind(el) && (!q || el.dataset.q.includes(q)); el.classList.toggle('hide', !ok); });
    document.querySelectorAll('.pr').forEach(el => { const ok = okSt(el) && okKind(el) && (!q || el.dataset.q.includes(q)); el.classList.toggle('d-none', !ok); if (ok) { n++; kg += +el.dataset.kg; left += +el.dataset.left; } });
    document.querySelectorAll('.pp-grp').forEach(g => {
      const vis = [...g.querySelectorAll('.pc:not(.hide)')];
      g.classList.toggle('d-none', !vis.length);
      const gk = vis.reduce((s, el) => s + +el.dataset.kg, 0), gl = vis.reduce((s, el) => s + +el.dataset.left, 0);
      g.querySelector('.pp-grp-sum').textContent = `${vis.length} بالتة · ${gl} في المخزن · ${gk.toLocaleString('en-US', { maximumFractionDigits: 1 })} كجم`;
    });
    $('ppCount').innerHTML = `<b>${n}</b> بالتة — <b>${left}</b> كرتونة/شكارة في المخزن — <b>${kg.toLocaleString('en-US', { maximumFractionDigits: 1 })}</b> كيلو`;
    $('ppEmpty').classList.toggle('d-none', n > 0);
    $('ppCards').classList.toggle('d-none', view !== 'cards' || !n);
    $('ppTable').classList.toggle('d-none', view !== 'table' || !n);
  }
  const chips = (id, set) => $(id).addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    $(id).querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    set(b.dataset.v); apply();
  });
  chips('ppState', v => { st = v; });
  chips('ppKind', v => { kind = v; });
  chips('ppView', v => { view = v; try { localStorage.setItem('ppView', v); } catch (e) {} });
  $('ppView').querySelectorAll('button').forEach(x => x.classList.toggle('on', x.dataset.v === view));
  $('ppQ').addEventListener('input', apply);
  // header numbers open what is behind them
  let modal = null;
  document.querySelector('.pp-kpis').addEventListener('click', async e => {
    const c = e.target.closest('[data-k]'); if (!c) return;
    modal = modal || new bootstrap.Modal($('ppModal'));
    $('ppmTitle').textContent = 'بيحمّل…'; $('ppmBody').innerHTML = '<div class="dd-load"><span class="spinner-border text-info"></span></div>';
    modal.show();
    try { const j = await (await fetch('pallets.php?detail=' + c.dataset.k, { cache: 'no-store' })).json(); $('ppmTitle').textContent = j.title; $('ppmBody').innerHTML = j.html; }
    catch (er) { $('ppmBody').innerHTML = '<div class="alert alert-danger">مفيش اتصال بالسيرفر</div>'; }
  });
  apply();
  requestAnimationFrame(() => setTimeout(() => document.querySelectorAll('.pc-stack i').forEach(i => i.classList.add('go')), 200));
})();
</script>
<?php require __DIR__ . '/inc/footer.php';
