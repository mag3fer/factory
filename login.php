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
$logo = setting('logo_file');
$logoSrc = ($logo && is_file(__DIR__ . '/' . $logo)) ? $logo . '?v=' . filemtime(__DIR__ . '/' . $logo) : 'assets/brand.svg';
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — <?= e($company) ?></title>
<link rel="icon" href="assets/brand.svg">
<link rel="stylesheet" href="assets/bootstrap.rtl.min.css">
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
body { background: #060d18; overflow: hidden; }
.stage { position: fixed; inset: 0; background:
  radial-gradient(1200px 600px at 80% -10%, rgba(37, 99, 235, .35), transparent 60%),
  radial-gradient(900px 500px at -10% 110%, rgba(34, 184, 216, .25), transparent 60%),
  linear-gradient(160deg, #07111f, #0b1a2e 55%, #08121f); }
.grid { position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px);
  background-size: 48px 48px; mask-image: radial-gradient(ellipse at center, #000 30%, transparent 75%); animation: gridMove 30s linear infinite; }
@keyframes gridMove { to { background-position: 48px 48px, 48px 48px; } }

/* the yarn machine scene */
.scene { position: absolute; inset: 0; width: 100%; height: 100%; }
.thread { fill: none; stroke-width: 2; stroke-linecap: round; stroke-dasharray: 14 10; animation: flow 2.4s linear infinite; }
.thread.t2 { animation-duration: 3.1s; } .thread.t3 { animation-duration: 2s; } .thread.t4 { animation-duration: 3.6s; }
@keyframes flow { to { stroke-dashoffset: -96; } }
.gear { transform-box: fill-box; transform-origin: center; animation: spin 14s linear infinite; }
.gear.rev { animation-direction: reverse; animation-duration: 10s; }
@keyframes spin { to { transform: rotate(360deg); } }
.cone-wind { animation: wind 1.2s linear infinite; }
@keyframes wind { to { transform: translateY(-12px); } }
.bob { transform-box: fill-box; transform-origin: center; animation: bob 3s ease-in-out infinite; }
.bob.d2 { animation-delay: -1s; } .bob.d3 { animation-delay: -2s; }
@keyframes bob { 50% { transform: translateY(-6px); } }

.fiber { position: absolute; width: 2px; height: 26px; border-radius: 2px; background: linear-gradient(transparent, rgba(255,255,255,.55), transparent); animation: rise linear infinite; opacity: .0; }
@keyframes rise { 0% { transform: translateY(110vh) rotate(0); opacity: 0; } 10% { opacity: .7; } 90% { opacity: .5; } 100% { transform: translateY(-20vh) rotate(160deg); opacity: 0; } }

/* login card */
.wrap { position: relative; z-index: 2; min-height: 100vh; display: flex; align-items: center; justify-content: flex-start; padding: 2rem 6vw; }
.login-card { width: 100%; max-width: 410px; padding: 2.2rem 2rem; border-radius: 22px; color: #e2e8f0;
  background: linear-gradient(160deg, rgba(255,255,255,.12), rgba(255,255,255,.04)); border: 1px solid rgba(255,255,255,.14);
  backdrop-filter: blur(16px); box-shadow: 0 30px 80px rgba(0,0,0,.45); animation: cardIn .9s cubic-bezier(.2,.8,.2,1) both; }
@keyframes cardIn { from { opacity: 0; transform: translateY(30px) scale(.97); } to { opacity: 1; transform: none; } }
.logo-ring { width: 120px; height: 120px; margin: 0 auto 1rem; border-radius: 50%; display: grid; place-items: center; position: relative;
  background: radial-gradient(circle, rgba(34,184,216,.25), rgba(34,184,216,0) 70%); }
.logo-ring::before { content: ""; position: absolute; inset: -4px; border-radius: 50%; border: 2px dashed rgba(34,184,216,.6); animation: spin 18s linear infinite; }
.logo-ring img { width: 96px; height: 96px; object-fit: contain; background: radial-gradient(circle, #fff 62%, #e2e8f0); border-radius: 50%; padding: 6px; box-shadow: 0 0 30px rgba(34,184,216,.35); animation: brandIn 1s ease both .2s; }
.login-card h1 { font-size: 1.7rem; font-weight: 800; letter-spacing: 1px; text-align: center; direction: ltr; margin: 0;
  background: linear-gradient(90deg, #fff, #7dd3fc, #fff); background-size: 200% auto; -webkit-background-clip: text; background-clip: text; color: transparent; animation: shine 5s linear infinite; }
@keyframes shine { to { background-position: 200% center; } }
.login-card .sub { text-align: center; color: #94a3b8; margin: .25rem 0 1.6rem; font-size: .92rem; }
.field { position: relative; margin-bottom: 1rem; animation: reveal .6s ease both; }
.field:nth-of-type(2) { animation-delay: .1s; }
.field i { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #7dd3fc; font-size: 1.1rem; }
.field input { width: 100%; height: 52px; border-radius: 14px; border: 1px solid rgba(255,255,255,.16); background: rgba(6, 13, 24, .55);
  color: #fff; padding: 0 44px 0 14px; font-size: 1rem; transition: border-color .2s, box-shadow .2s, background .2s; }
.field input::placeholder { color: #64748b; }
.field input:focus { outline: none; border-color: #22b8d8; box-shadow: 0 0 0 4px rgba(34,184,216,.18); background: rgba(6, 13, 24, .75); }
.toggle-pass { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: #94a3b8; }
.btn-login { width: 100%; height: 52px; border: 0; border-radius: 14px; font-weight: 800; font-size: 1.05rem; color: #fff; position: relative; overflow: hidden;
  background: linear-gradient(135deg, #22b8d8, #2563eb); box-shadow: 0 10px 30px rgba(37,99,235,.45); transition: transform .15s, box-shadow .2s; }
.btn-login:hover { transform: translateY(-1px); box-shadow: 0 14px 34px rgba(37,99,235,.55); }
.btn-login::after { content: ""; position: absolute; top: 0; left: -60%; width: 40%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent);
  transform: skewX(-20deg); animation: sweep 3.2s ease-in-out infinite; }
@keyframes sweep { 60%, 100% { left: 130%; } }
.btn-login .spin { display: none; width: 22px; height: 22px; border: 3px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto; }
.btn-login.loading span { display: none; } .btn-login.loading .spin { display: block; }
.err { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.4); color: #fecaca; border-radius: 12px; padding: .6rem .9rem; margin-bottom: 1rem; animation: shake .45s ease; }
@keyframes shake { 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }
.foot { text-align: center; color: #64748b; font-size: .78rem; margin-top: 1.4rem; }
.tagline { position: absolute; z-index: 2; left: 6vw; bottom: 7vh; color: #cbd5e1; max-width: 420px; animation: reveal 1s ease both .5s; }
.tagline b { display: block; font-size: 1.6rem; color: #fff; font-weight: 800; }
@media (max-width: 991px) { .wrap { justify-content: center; } .tagline { display: none; } .scene { opacity: .35; } }
</style>
</head>
<body>
<div class="stage">
  <div class="grid"></div>
  <!-- animated yarn machine: bobbins feed threads through guides to winding cones, gears turning -->
  <svg class="scene" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
    <defs>
      <linearGradient id="cone" x1="0" x2="1"><stop offset="0" stop-color="#e2e8f0"/><stop offset=".5" stop-color="#ffffff"/><stop offset="1" stop-color="#cbd5e1"/></linearGradient>
      <linearGradient id="thr" x1="0" x2="1"><stop offset="0" stop-color="#22d3ee" stop-opacity=".1"/><stop offset=".5" stop-color="#7dd3fc"/><stop offset="1" stop-color="#22d3ee" stop-opacity=".1"/></linearGradient>
      <pattern id="windings" width="8" height="12" patternUnits="userSpaceOnUse"><rect width="8" height="12" fill="url(#cone)"/><path d="M0 6h8" stroke="#94a3b8" stroke-opacity=".5"/></pattern>
      <clipPath id="c1"><path d="M0 0h70l25 150H-25z"/></clipPath>
      <g id="gearShape"><path fill="currentColor" d="M50 0l6 12a40 40 0 0 1 12 5l13-4 7 12-9 10a40 40 0 0 1 0 13l9 10-7 12-13-4a40 40 0 0 1-12 5l-6 12H36l-6-12a40 40 0 0 1-12-5l-13 4-7-12 9-10a40 40 0 0 1 0-13l-9-10 7-12 13 4a40 40 0 0 1 12-5l6-12z" transform="translate(-43 -43)"/><circle r="14" fill="#07111f"/></g>
    </defs>

    <!-- gears -->
    <g color="#1e3a5f" transform="translate(1460 140) scale(1.7)"><use href="#gearShape" class="gear"/></g>
    <g color="#16304f" transform="translate(1318 268) scale(1.05)"><use href="#gearShape" class="gear rev"/></g>
    <g color="#132a45" transform="translate(150 790) scale(1.3)"><use href="#gearShape" class="gear"/></g>

    <!-- machine on the left, threads running right toward the login card (mirrored group) -->
    <g transform="translate(1600 0) scale(-1 1)">
      <path class="thread" stroke="url(#thr)" d="M1180 300 C 1000 160, 820 420, 620 250 S 260 200, -40 300"/>
      <path class="thread t2" stroke="url(#thr)" d="M1290 340 C 1080 240, 900 500, 660 340 S 300 300, -40 400"/>
      <path class="thread t3" stroke="url(#thr)" d="M1400 380 C 1180 300, 980 560, 720 420 S 340 380, -40 500"/>
      <path class="thread t4" stroke="url(#thr)" stroke-opacity=".6" d="M1500 160 C 1250 120, 1050 280, 820 190 S 400 140, -40 220"/>
      <g transform="translate(1135 300)"><g class="bob"><g clip-path="url(#c1)"><rect class="cone-wind" x="-30" y="0" width="130" height="170" fill="url(#windings)"/></g><rect x="20" y="-14" width="30" height="16" rx="4" fill="#f59e0b"/></g></g>
      <g transform="translate(1245 340)"><g class="bob d2"><g clip-path="url(#c1)"><rect class="cone-wind" x="-30" y="0" width="130" height="170" fill="url(#windings)"/></g><rect x="20" y="-14" width="30" height="16" rx="4" fill="#22b8d8"/></g></g>
      <g transform="translate(1355 380)"><g class="bob d3"><g clip-path="url(#c1)"><rect class="cone-wind" x="-30" y="0" width="130" height="170" fill="url(#windings)"/></g><rect x="20" y="-14" width="30" height="16" rx="4" fill="#a78bfa"/></g></g>
      <rect x="1080" y="540" width="420" height="14" rx="7" fill="#1e3a5f"/>
    </g>
  </svg>
  <div id="fibers"></div>
</div>

<div class="wrap">
  <div class="login-card">
    <div class="logo-ring"><img src="<?= e($logoSrc) ?>" alt=""></div>
    <h1><?= e($company) ?></h1>
    <div class="sub">نظام إدارة مصنع الخيوط — الإنتاج والمخزن والمبيعات</div>
    <?php if ($error): ?><div class="err"><i class="bi bi-exclamation-triangle"></i> <?= e($error) ?></div><?php endif; ?>
    <form method="post" id="loginForm" autocomplete="on">
      <?= csrf_field() ?>
      <div class="field"><i class="bi bi-person"></i><input name="username" placeholder="اسم المستخدم" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></div>
      <div class="field"><i class="bi bi-lock"></i><input name="password" id="pw" type="password" placeholder="كلمة المرور" required>
        <button type="button" class="toggle-pass" onclick="const p=document.getElementById('pw');p.type=p.type==='password'?'text':'password';this.firstElementChild.className=p.type==='password'?'bi bi-eye':'bi bi-eye-slash'"><i class="bi bi-eye"></i></button></div>
      <button class="btn-login" id="btnLogin"><span><i class="bi bi-box-arrow-in-left"></i> دخول</span><div class="spin"></div></button>
    </form>
    <div class="foot">© <?= date('Y') ?> <?= e($company) ?> — مدينة بدر</div>
  </div>
</div>
<div class="tagline"><b>من البوي للكونة… كل كيلو محسوب.</b>متابعة الخام والإنتاج والميزان والمبيعات في مكان واحد.</div>

<script>
document.getElementById('loginForm').addEventListener('submit', () => document.getElementById('btnLogin').classList.add('loading'));
// floating fibers
const box = document.getElementById('fibers');
for (let i = 0; i < 26; i++) {
  const f = document.createElement('span');
  f.className = 'fiber';
  f.style.left = Math.random() * 100 + 'vw';
  f.style.animationDuration = 9 + Math.random() * 12 + 's';
  f.style.animationDelay = -Math.random() * 20 + 's';
  f.style.height = 14 + Math.random() * 28 + 'px';
  box.appendChild(f);
}
</script>
</body>
</html>
