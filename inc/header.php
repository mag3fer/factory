<?php
/** @var string $pageTitle */
$u = current_user();
// [href, label, icon, roles]; a string entry starts a group
$nav = [
    ['index.php', 'لوحة التحكم', 'speedometer2', ['admin', 'store']],
    'الإنتاج',
    ['scale.php', 'الميزان', 'speedometer', ['admin', 'scale']],
    ['packages.php', 'الكراتين المنتجة', 'box-seam', ['admin', 'store', 'scale']],
    ['pallets.php', 'البالتات', 'boxes', ['admin', 'store', 'scale']],
    'المبيعات',
    ['sale_new.php', 'فاتورة بيع / إذن تسليم', 'upc-scan', ['admin', 'store']],
    ['sales.php', 'فواتير البيع', 'receipt', ['admin', 'store']],
    ['payments.php', 'التحصيلات والمدفوعات', 'cash-coin', ['admin', 'store']],
    ['balances.php', 'أرصدة العملاء', 'people', ['admin', 'store']],
    'المخزن',
    ['stock.php', 'جرد المخزن', 'boxes', ['admin', 'store']],
    ['docs.php', 'الاستلام والصرف', 'arrow-left-right', ['admin', 'store']],
    ['items.php', 'الأصناف', 'tags', ['admin', 'store']],
    ['packing.php', 'أنواع التعبئة', 'bag', ['admin']],
    'العمال والمرتبات',
    ['workers.php', 'العاملين والسواقين', 'people-fill', ['admin']],
    ['attendance.php', 'الحضور', 'calendar-check', ['admin']],
    ['hr_entries.php', 'السلف والخصومات', 'cash-stack', ['admin']],
    ['payroll.php', 'المرتبات', 'wallet2', ['admin']],
    'النقل',
    ['transport.php', 'المشاوير', 'signpost-split', ['admin']],
    ['vehicles.php', 'المركبات وأسعار المشاوير', 'truck', ['admin']],
    'الإدارة',
    ['parties.php', 'العملاء والموردين', 'person-vcard', ['admin', 'store']],
    ['users.php', 'المستخدمين', 'shield-lock', ['admin']],
    ['settings.php', 'الإعدادات', 'gear', ['admin']],
    ['backup.php', 'نسخة احتياطية وتصفير', 'database', ['admin']],
];
$current = basename($_SERVER['SCRIPT_NAME']);
$logo = setting('logo_file');
$logoSrc = ($logo && is_file(__DIR__ . '/../' . $logo)) ? $logo . '?v=' . filemtime(__DIR__ . '/../' . $logo) : 'assets/brand.svg';
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'المصنع') ?> — <?= e(setting('company_name', 'المصنع')) ?></title>
<link rel="icon" href="assets/brand.svg">
<link rel="stylesheet" href="assets/bootstrap.rtl.min.css">
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/../assets/app.css') ?>">
</head>
<body>
<?php if ($u): ?>
<div class="app" id="app">
  <aside class="sidebar no-print">
    <a class="brand" href="<?= $u['role'] === 'scale' ? 'scale.php' : 'index.php' ?>">
      <img src="<?= e($logoSrc) ?>" alt="">
      <div><div class="t1"><?= e(setting('company_name', 'TEXTILE LAND')) ?></div><div class="t2">نظام إدارة المصنع</div></div>
    </a>
    <nav>
    <?php $pendingGroup = null; foreach ($nav as $n):
        if (is_string($n)) { $pendingGroup = $n; continue; }
        [$href, $label, $icon, $roles] = $n;
        if (!in_array($u['role'], $roles, true)) continue;
        if ($pendingGroup) { echo '<div class="group">' . e($pendingGroup) . '</div>'; $pendingGroup = null; } ?>
      <a class="nav-link<?= $current === $href ? ' active' : '' ?>" href="<?= $href ?>"><i class="bi bi-<?= $icon ?>"></i><span><?= $label ?></span></a>
    <?php endforeach; ?>
    </nav>
  </aside>
  <div class="main">
    <header class="topbar no-print">
      <button class="btn btn-light sidebar-toggle" onclick="document.getElementById('app').classList.toggle('sidebar-open')"><i class="bi bi-list"></i></button>
      <h1 class="title"><?= e($pageTitle ?? '') ?></h1>
      <span class="clock ms-auto d-none d-md-inline" id="clock"></span>
      <div class="user">
        <div class="avatar"><?= e(mb_substr($u['full_name'], 0, 1)) ?></div>
        <div class="d-none d-sm-block lh-sm"><div class="fw-bold small"><?= e($u['full_name']) ?></div><div class="text-muted" style="font-size:.72rem"><?= ROLE_NAMES[$u['role']] ?></div></div>
        <a class="btn btn-sm btn-outline-secondary ms-2" href="logout.php" title="خروج"><i class="bi bi-box-arrow-left"></i></a>
      </div>
    </header>
    <main class="content">
<?php else: ?>
<main>
<?php endif; ?>
<?php if ($f = flash()): ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show no-print"><?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
