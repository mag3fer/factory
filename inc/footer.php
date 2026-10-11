<?php if (current_user()): ?>
    </main>
  </div>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<script src="assets/bootstrap.bundle.min.js"></script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
<?php foreach ($pageScripts ?? [] as $s): ?>
<script src="<?= e($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
