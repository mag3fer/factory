<?php
/** @var string $pageTitle */
$u = current_user();
$nav = [
    ['index.php', 'الرئيسية', ['admin', 'store', 'scale']],
    ['scale.php', 'الميزان', ['admin', 'scale']],
    ['packages.php', 'الكراتين المنتجة', ['admin', 'store', 'scale']],
    ['docs.php', 'المستندات', ['admin', 'store']],
    ['stock.php', 'المخزن', ['admin', 'store']],
    ['items.php', 'الأصناف', ['admin', 'store']],
    ['parties.php', 'العملاء والموردين', ['admin', 'store']],
    ['packing.php', 'أنواع التعبئة', ['admin']],
    ['users.php', 'المستخدمين', ['admin']],
    ['settings.php', 'الإعدادات', ['admin']],
];
$current = basename($_SERVER['SCRIPT_NAME']);
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'المصنع') ?> — <?= e(setting('company_name', 'المصنع')) ?></title>
<link rel="stylesheet" href="assets/bootstrap.rtl.min.css">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php if ($u): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3 no-print">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="index.php"><?= e(setting('company_name', 'المصنع')) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($nav as [$href, $label, $roles]): if (!in_array($u['role'], $roles, true)) continue; ?>
          <li class="nav-item"><a class="nav-link<?= $current === $href ? ' active fw-bold' : '' ?>" href="<?= $href ?>"><?= $label ?></a></li>
        <?php endforeach; ?>
      </ul>
      <span class="navbar-text text-light small me-3"><?= e($u['full_name']) ?> (<?= ROLE_NAMES[$u['role']] ?>)</span>
      <a class="btn btn-outline-light btn-sm" href="logout.php">خروج</a>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="container-fluid px-3">
<?php if ($f = flash()): ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show no-print"><?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
