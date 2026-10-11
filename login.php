<?php
require __DIR__ . '/inc/bootstrap.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = q('SELECT * FROM users WHERE username=? AND active=1', [trim($_POST['username'] ?? '')])->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role']];
        redirect($user['role'] === 'scale' ? 'scale.php' : 'index.php');
    }
    $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
}
if (current_user()) redirect(current_user()['role'] === 'scale' ? 'scale.php' : 'index.php');

$company = setting('company_name', 'TEXTILE LAND');
$asset = function (string $key, string $fallback): string {
    $f = setting($key);
    return ($f && is_file(__DIR__ . '/' . $f)) ? $f . '?v=' . filemtime(__DIR__ . '/' . $f) : $fallback;
};
$logoFull = $asset('logo_full', $asset('logo_file', 'assets/brand.svg'));
// production stages shown over the factory photos (files in assets/login/)
$slides = [
    ['1-poy.jpg', 'الخام', 'POY بالبالتات', 'خام بوليستر مستورد بلوطات ومواصفات مختلفة — كل لوط بيتسجل لوحده.'],
    ['2-creel.jpg', 'التغذية', 'تحميل البوي على الكريل', 'الخام بيتحط على الكريل ويدخل الماكينة خيط خيط.'],
    ['3-machine.jpg', 'التكسير', 'ماكينات DTY', 'تكسير وتسخين الخيط وتحويله لخيط بوليستر جاهز.'],
    ['4-cones.jpg', 'الإنتاج', 'كونات جاهزة', 'كل كونة بتتوزن وتتعبى وتاخد ستيكر باركود.'],
    ['5-cone.jpg', 'الجودة', 'منتج نهائي', 'من البوي للكونة… كل كيلو محسوب.'],
];
$slides = array_values(array_filter($slides, fn($s) => is_file(__DIR__ . '/assets/login/' . $s[0])));
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — <?= e($company) ?></title>
<link rel="icon" href="<?= e($asset('logo_file', 'assets/brand.svg')) ?>">
<link rel="stylesheet" href="assets/bootstrap.rtl.min.css">
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
html, body { height: 100%; }
body { margin: 0; background: #0a1422; overflow: hidden; }
.login-shell { display: grid; grid-template-columns: minmax(420px, 34%) 1fr; height: 100vh; }

/* ---------- form panel ---------- */
.panel { position: relative; z-index: 3; background: #fff; display: flex; flex-direction: column; justify-content: center; padding: 3rem clamp(2rem, 4vw, 4.5rem);
  box-shadow: 30px 0 80px rgba(0,0,0,.35); animation: panelIn .9s cubic-bezier(.2,.8,.2,1) both; }
@keyframes panelIn { from { transform: translateX(40px); opacity: 0; } to { transform: none; opacity: 1; } }
.panel::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 5px; background: linear-gradient(90deg, #0e7490, #22b8d8, #2563eb, #0e7490); background-size: 200% 100%; animation: bar 6s linear infinite; }
@keyframes bar { to { background-position: 200% 0; } }
.brand-logo { display: block; width: min(300px, 80%); margin: 0 auto 1.4rem; animation: logoIn 1.1s cubic-bezier(.2,.8,.2,1) both .15s; }
@keyframes logoIn { from { opacity: 0; transform: scale(.85) translateY(10px); filter: blur(6px); } to { opacity: 1; transform: none; filter: none; } }
.welcome { text-align: center; margin-bottom: 1.8rem; animation: up .7s ease both .35s; }
.welcome h1 { font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0 0 .25rem; }
.welcome p { color: #64748b; margin: 0; font-size: .92rem; }
@keyframes up { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }

.fl { position: relative; margin-bottom: 1.05rem; animation: up .7s ease both; }
.fl:nth-of-type(1) { animation-delay: .45s; } .fl:nth-of-type(2) { animation-delay: .55s; }
.fl input { width: 100%; height: 56px; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 1.15rem 2.9rem .3rem 1rem; font-size: 1rem; color: #0f172a; background: #f8fafc;
  transition: border-color .2s, box-shadow .2s, background .2s; }
.fl input:focus { outline: none; border-color: #22b8d8; background: #fff; box-shadow: 0 0 0 4px rgba(34,184,216,.14); }
.fl label { position: absolute; right: 2.9rem; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; transition: all .18s ease; font-size: .98rem; }
.fl input:focus + label, .fl input:not(:placeholder-shown) + label { top: .85rem; font-size: .72rem; color: #0e7490; font-weight: 700; }
.fl > i { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.15rem; transition: color .2s; }
.fl input:focus ~ i { color: #0e7490; }
.eye { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); border: 0; background: none; color: #94a3b8; font-size: 1.1rem; padding: .3rem; }
.eye:hover { color: #0f172a; }

.btn-go { width: 100%; height: 54px; border: 0; border-radius: 12px; color: #fff; font-weight: 800; font-size: 1.05rem; position: relative; overflow: hidden;
  background: linear-gradient(135deg, #0b1726, #12345a 55%, #0e7490); box-shadow: 0 12px 28px rgba(14,116,144,.32); transition: transform .15s, box-shadow .2s; animation: up .7s ease both .65s; }
.btn-go:hover { transform: translateY(-2px); box-shadow: 0 16px 34px rgba(14,116,144,.42); }
.btn-go::after { content: ""; position: absolute; inset: 0; background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,.25) 50%, transparent 70%); transform: translateX(-100%); animation: sheen 3.5s ease-in-out infinite 1.5s; }
@keyframes sheen { 40%, 100% { transform: translateX(100%); } }
.btn-go .sp { display: none; width: 22px; height: 22px; border: 3px solid rgba(255,255,255,.35); border-top-color: #fff; border-radius: 50%; animation: spin .8s linear infinite; margin: auto; }
@keyframes spin { to { transform: rotate(360deg); } }
.btn-go.loading .tx { display: none; } .btn-go.loading .sp { display: block; }
.err { display: flex; gap: .5rem; align-items: center; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 12px; padding: .7rem .9rem; margin-bottom: 1rem; animation: shake .45s ease; }
@keyframes shake { 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }
.panel-foot { margin-top: 2.2rem; text-align: center; color: #94a3b8; font-size: .8rem; animation: up .7s ease both .8s; }
.panel-foot .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981; margin-left: .35rem; box-shadow: 0 0 0 0 rgba(16,185,129,.6); animation: pulse 2s infinite; }
@keyframes pulse { 70% { box-shadow: 0 0 0 9px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }

/* ---------- photo showcase ---------- */
.show { position: relative; overflow: hidden; background: #0a1422; }
.slide { position: absolute; inset: 0; opacity: 0; transition: opacity 1.6s ease; }
.slide.on { opacity: 1; }
.slide img { width: 100%; height: 100%; object-fit: cover; transform: scale(1.12); }
.slide.on img { animation: kb 9s ease-out forwards; }
.slide:nth-child(even).on img { animation-name: kb2; }
@keyframes kb { from { transform: scale(1.18) translate(2%, 1%); } to { transform: scale(1.04) translate(0, 0); } }
@keyframes kb2 { from { transform: scale(1.04) translate(-1%, 0); } to { transform: scale(1.18) translate(1%, -2%); } }
.show::after { content: ""; position: absolute; inset: 0; z-index: 1; pointer-events: none;
  background: linear-gradient(0deg, rgba(5,12,22,.92) 0%, rgba(5,12,22,.35) 45%, rgba(5,12,22,.15) 70%, rgba(5,12,22,.55) 100%),
              linear-gradient(90deg, rgba(14,116,144,.25), transparent 40%); }
/* fine thread lines drifting across the photos */
.threads { position: absolute; inset: 0; z-index: 2; pointer-events: none; opacity: .5; }
.threads path { fill: none; stroke: url(#tg); stroke-width: 1.2; stroke-dasharray: 2 14; animation: run 7s linear infinite; }
.threads path:nth-child(3) { animation-duration: 10s; } .threads path:nth-child(4) { animation-duration: 13s; }
@keyframes run { to { stroke-dashoffset: -320; } }

.top-strip { position: absolute; top: 1.6rem; left: 2rem; right: 2rem; z-index: 3; display: flex; justify-content: space-between; align-items: center; color: #e2e8f0; }
.badge-live { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); backdrop-filter: blur(8px); border-radius: 999px; padding: .4rem .9rem; font-size: .82rem; }
.caption { position: absolute; z-index: 3; right: clamp(2rem, 5vw, 5rem); left: clamp(2rem, 5vw, 5rem); bottom: 7.5rem; color: #fff; max-width: 640px; }
.caption .step { display: inline-flex; align-items: center; gap: .5rem; font-weight: 700; color: #67e8f9; letter-spacing: .5px; font-size: .9rem; }
.caption .step .n { width: 30px; height: 30px; border-radius: 50%; border: 1.5px solid #67e8f9; display: grid; place-items: center; font-size: .85rem; }
.caption h2 { font-size: clamp(2rem, 3.4vw, 3.2rem); font-weight: 800; margin: .5rem 0 .4rem; line-height: 1.15; text-shadow: 0 4px 24px rgba(0,0,0,.4); }
.caption p { font-size: 1.1rem; color: #cbd5e1; margin: 0; }
.caption > * { opacity: 0; transform: translateY(18px); }
.caption.in > * { animation: capIn .8s cubic-bezier(.2,.8,.2,1) forwards; }
.caption.in > *:nth-child(2) { animation-delay: .12s; } .caption.in > *:nth-child(3) { animation-delay: .24s; }
@keyframes capIn { to { opacity: 1; transform: none; } }

/* process timeline */
.timeline { position: absolute; z-index: 3; bottom: 2.4rem; right: clamp(2rem, 5vw, 5rem); left: clamp(2rem, 5vw, 5rem); display: flex; gap: .6rem; }
.timeline button { flex: 1; border: 0; background: none; padding: 0; text-align: right; color: #94a3b8; font-size: .8rem; font-weight: 700; cursor: pointer; }
.timeline .track { height: 3px; background: rgba(255,255,255,.18); border-radius: 3px; overflow: hidden; margin-bottom: .45rem; }
.timeline .fill { height: 100%; width: 0; background: linear-gradient(90deg, #22b8d8, #67e8f9); }
.timeline button.done .fill { width: 100%; }
.timeline button.on { color: #fff; }
.timeline button.on .fill { animation: fill var(--dur) linear forwards; }
@keyframes fill { to { width: 100%; } }

@media (max-width: 991px) {
  .login-shell { display: flex; align-items: center; justify-content: center; padding: 1rem; }
  .show { position: fixed; inset: 0; }
  .panel { margin: 0; width: 100%; max-width: 440px; min-width: 0; border-radius: 20px; padding: 2.2rem 1.6rem; height: auto; box-shadow: 0 30px 80px rgba(0,0,0,.5); }
  .caption, .timeline, .top-strip { display: none; }
  .panel { animation-name: up; }
}
@media (prefers-reduced-motion: reduce) { .slide.on img, .threads path { animation: none !important; } }
</style>
</head>
<body>
<div class="login-shell">
  <section class="panel">
    <img class="brand-logo" src="<?= e($logoFull) ?>" alt="<?= e($company) ?>">
    <div class="welcome"><h1>أهلاً بيك 👋</h1><p>سجّل دخولك لنظام إدارة المصنع</p></div>
    <?php if ($error): ?><div class="err"><i class="bi bi-exclamation-octagon"></i><?= e($error) ?></div><?php endif; ?>
    <form method="post" id="f" autocomplete="on">
      <?= csrf_field() ?>
      <div class="fl"><input name="username" id="u" placeholder=" " required autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>"><label for="u">اسم المستخدم</label><i class="bi bi-person"></i></div>
      <div class="fl"><input name="password" id="pw" type="password" placeholder=" " required autocomplete="current-password"><label for="pw">كلمة المرور</label><i class="bi bi-shield-lock"></i>
        <button type="button" class="eye" id="eye" aria-label="إظهار كلمة المرور"><i class="bi bi-eye"></i></button></div>
      <button class="btn-go" id="go"><span class="tx"><i class="bi bi-box-arrow-in-left ms-1"></i> تسجيل الدخول</span><span class="sp"></span></button>
    </form>
    <div class="panel-foot"><span class="dot"></span>السيرفر شغال — <?= e($company) ?> © <?= date('Y') ?> — مدينة بدر</div>
  </section>

  <section class="show" aria-hidden="true">
    <?php foreach ($slides as $i => $s): ?>
      <div class="slide<?= $i === 0 ? ' on' : '' ?>"><img src="assets/login/<?= e($s[0]) ?>?v=<?= filemtime(__DIR__ . '/assets/login/' . $s[0]) ?>" alt=""></div>
    <?php endforeach; ?>
    <svg class="threads" viewBox="0 0 1000 600" preserveAspectRatio="none">
      <defs><linearGradient id="tg" x1="0" x2="1"><stop offset="0" stop-color="#67e8f9" stop-opacity="0"/><stop offset=".5" stop-color="#e0f2fe"/><stop offset="1" stop-color="#67e8f9" stop-opacity="0"/></linearGradient></defs>
      <path d="M0 140 C 250 80, 420 230, 650 150 S 900 90, 1000 130"/>
      <path d="M0 210 C 220 160, 460 300, 700 220 S 920 170, 1000 200"/>
      <path d="M0 280 C 260 230, 480 360, 720 290 S 930 250, 1000 270"/>
    </svg>
    <div class="top-strip">
      <span class="badge-live"><i class="bi bi-geo-alt"></i> مدينة بدر — مصنع خيوط البوليستر</span>
      <span class="badge-live" id="clock"></span>
    </div>
    <div class="caption" id="cap"><div class="step"><span class="n" id="capN">1</span><span id="capK"></span></div><h2 id="capT"></h2><p id="capD"></p></div>
    <div class="timeline" id="tl">
      <?php foreach ($slides as $i => $s): ?><button type="button" data-i="<?= $i ?>"><div class="track"><div class="fill"></div></div><?= e($s[1]) ?></button><?php endforeach; ?>
    </div>
  </section>
</div>
<script>
(() => {
  const slides = <?= json_encode(array_map(fn($s) => ['k' => $s[1], 't' => $s[2], 'd' => $s[3]], $slides), JSON_UNESCAPED_UNICODE) ?>;
  const els = document.querySelectorAll('.slide'), btns = document.querySelectorAll('#tl button'), cap = document.getElementById('cap');
  const DUR = 6500; let cur = -1, timer;
  document.documentElement.style.setProperty('--dur', DUR + 'ms');
  function go(i) {
    if (!slides.length) return;
    cur = (i + slides.length) % slides.length;
    els.forEach((e, j) => e.classList.toggle('on', j === cur));
    btns.forEach((b, j) => { b.classList.toggle('on', j === cur); b.classList.toggle('done', j < cur); const f = b.querySelector('.fill'); f.style.animation = 'none'; f.offsetWidth; f.style.animation = ''; });
    const s = slides[cur];
    cap.classList.remove('in'); void cap.offsetWidth;
    document.getElementById('capN').textContent = cur + 1;
    document.getElementById('capK').textContent = s.k;
    document.getElementById('capT').textContent = s.t;
    document.getElementById('capD').textContent = s.d;
    cap.classList.add('in');
    clearTimeout(timer); timer = setTimeout(() => go(cur + 1), DUR);
  }
  btns.forEach(b => b.addEventListener('click', () => go(+b.dataset.i)));
  go(0);

  const clock = document.getElementById('clock');
  const tick = () => clock.innerHTML = '<i class="bi bi-clock"></i> ' + new Date().toLocaleString('ar-EG', { weekday: 'long', hour: '2-digit', minute: '2-digit' });
  tick(); setInterval(tick, 30000);

  document.getElementById('eye').addEventListener('click', function () {
    const p = document.getElementById('pw'); p.type = p.type === 'password' ? 'text' : 'password';
    this.firstElementChild.className = p.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
  });
  document.getElementById('f').addEventListener('submit', () => document.getElementById('go').classList.add('loading'));
})();
</script>
</body>
</html>
