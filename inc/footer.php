</main>
<script src="assets/bootstrap.bundle.min.js"></script>
<?php foreach ($pageScripts ?? [] as $s): ?>
<script src="<?= e($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
