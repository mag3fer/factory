<?php
// Calibration sticker: a frame at the very edge of the page plus mm rulers from every side, so one printed
// sticker shows how much of the page the printer really prints (and whether it is shifted).
require __DIR__ . '/inc/bootstrap.php';
require_role();
$w = (int)setting('label_width_mm', '76');
$h = (int)setting('label_height_mm', '130');
?><!doctype html>
<html lang="en" dir="ltr"><head><meta charset="utf-8"><title>Label test</title>
<style>
@page { size: <?= $w ?>mm <?= $h ?>mm; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { width: <?= $w ?>mm; height: <?= $h ?>mm; position: relative; overflow: hidden; font: 700 7pt Arial, sans-serif; color: #000; }
.frame { position: absolute; inset: 0; border: .6mm solid #000; }
.tick { position: absolute; background: #000; }
.n { position: absolute; font-size: 7pt; line-height: 1; }
.c { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); text-align: center; font-size: 9pt; }
@media screen { body { margin: 10px auto; outline: 1px dashed #999; } }
</style></head>
<body>
<div class="frame"></div>
<?php for ($mm = 0; $mm <= $w; $mm += 5): $len = $mm % 10 === 0 ? 5 : 3; ?>
  <div class="tick" style="left:<?= $mm ?>mm;top:0;width:.3mm;height:<?= $len ?>mm"></div>
  <div class="tick" style="left:<?= $mm ?>mm;bottom:0;width:.3mm;height:<?= $len ?>mm"></div>
  <?php if ($mm % 10 === 0): ?>
    <div class="n" style="left:<?= $mm ?>mm;top:5.5mm;margin-left:-1.5mm"><?= $mm ?></div>
    <div class="n" style="left:<?= $mm ?>mm;bottom:5.5mm;margin-left:-1.5mm"><?= $w - $mm ?></div>
  <?php endif; ?>
<?php endfor; ?>
<?php for ($mm = 0; $mm <= $h; $mm += 5): $len = $mm % 10 === 0 ? 5 : 3; ?>
  <div class="tick" style="top:<?= $mm ?>mm;left:0;height:.3mm;width:<?= $len ?>mm"></div>
  <div class="tick" style="top:<?= $mm ?>mm;right:0;height:.3mm;width:<?= $len ?>mm"></div>
  <?php if ($mm % 10 === 0 && $mm > 0 && $mm < $h): ?>
    <div class="n" style="top:<?= $mm ?>mm;left:5.5mm;margin-top:-1mm"><?= $mm ?></div>
    <div class="n" style="top:<?= $mm ?>mm;right:5.5mm;margin-top:-1mm"><?= $mm ?></div>
  <?php endif; ?>
<?php endfor; ?>
<div class="c">PRINT TEST<br><?= $w ?> x <?= $h ?> mm<br>top ruler: mm from LEFT<br>bottom ruler: mm from RIGHT</div>
</body></html>
