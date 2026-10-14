<?php
// Backup + reset of test data: a full SQL backup is always taken before anything is deleted.
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('admin');

const BACKUP_DIR = __DIR__ . '/backups';

function backup_dir(): string
{
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0775, true);
    if (!is_file(BACKUP_DIR . '/.htaccess')) file_put_contents(BACKUP_DIR . '/.htaccess', "Require all denied\n");
    return BACKUP_DIR;
}

/** Full SQL dump of the database into backups/ (restore by importing it in phpMyAdmin). Returns the file name. */
function make_backup(string $tag): string
{
    $pdo = db();
    $name = 'factory_' . date('Ymd_His') . "_$tag.sql";
    $fh = fopen(backup_dir() . "/$name", 'w');
    fwrite($fh, '-- TEXTILE LAND factory backup ' . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$table, $kind]) {
        if ($kind !== 'BASE TABLE') continue;
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n$create;\n");
        foreach ($pdo->query("SELECT * FROM `$table`", PDO::FETCH_NUM) as $r) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r);
            fwrite($fh, "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return $name;
}

const RESETS = [
    'production' => ['الإنتاج', 'كل الكراتين المنتجة — والكونات والكراتين الفاضية اللي اتخصمت عليها ترجع للرصيد'],
    'sales' => ['المبيعات', 'فواتير البيع والمرتجعات والتحصيلات والمدفوعات'],
    'raw' => ['رصيد الخام', 'كل حركات الخام POY (استلام / صرف / تسوية / افتتاحي) — الرصيد يبقى صفر'],
];

if (isset($_GET['download'])) {
    $name = basename((string)$_GET['download']);
    $file = BACKUP_DIR . "/$name";
    if (!preg_match('/^factory_[\w-]+\.sql$/', $name) || !is_file($file)) {
        http_response_code(404);
        exit('الملف مش موجود');
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'backup') {
        flash('تم عمل نسخة احتياطية: ' . make_backup('manual'));
        redirect('backup.php');
    }

    $chosen = array_values(array_intersect(array_keys(RESETS), (array)($_POST['reset'] ?? [])));
    if (!$chosen) {
        flash('اختار حاجة واحدة على الأقل تتصفّر', 'danger');
        redirect('backup.php');
    }
    $me = q('SELECT password_hash FROM users WHERE id=?', [(int)$u['id']])->fetch();
    if (!$me || !password_verify($_POST['password'] ?? '', $me['password_hash'])) {
        flash('كلمة السر غلط — ماتصفّرش حاجة', 'danger');
        redirect('backup.php');
    }
    $do = array_flip($chosen);
    if (isset($do['production']) && !isset($do['sales']) && q('SELECT COUNT(*) FROM sales_packages')->fetchColumn()) {
        flash('فيه كراتين اتباعت في فواتير — لازم تختار «المبيعات» مع «الإنتاج»', 'danger');
        redirect('backup.php');
    }

    $file = make_backup('before_reset');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (isset($do['sales'])) {
            q('DELETE FROM payments');
            q('DELETE FROM sales_packages');
            q('DELETE FROM sales_lines');
            q("DELETE FROM stock_moves WHERE source='sale'");
            q('UPDATE sales_invoices SET ref_invoice_id=NULL');
            q('DELETE FROM sales_invoices');
            q("UPDATE packages SET status='in_stock' WHERE status='sold'");
        }
        if (isset($do['production'])) {
            q("DELETE FROM stock_moves WHERE source='package'");
            q('DELETE FROM packages');
        }
        if (isset($do['raw'])) {
            q("DELETE m FROM stock_moves m JOIN items i ON i.id=m.item_id WHERE i.type='raw'");
            q("DELETE l FROM doc_lines l JOIN items i ON i.id=l.item_id WHERE i.type='raw'");
            q('DELETE d FROM docs d WHERE NOT EXISTS (SELECT 1 FROM doc_lines l WHERE l.doc_id=d.id)');
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('حصل خطأ ومااتمسحش حاجة: ' . $ex->getMessage(), 'danger');
        redirect('backup.php');
    }
    flash('تم تصفير: ' . implode('، ', array_map(fn($k) => RESETS[$k][0], $chosen)) . " — النسخة الاحتياطية قبل التصفير: $file");
    redirect('backup.php');
}

$counts = [
    'production' => q("SELECT COUNT(*) n, COALESCE(SUM(net),0) kg FROM packages WHERE status<>'void'")->fetch(),
    'sales' => q('SELECT COUNT(*) n, COALESCE(SUM(total),0) kg FROM sales_invoices')->fetch(),
    'raw' => q("SELECT COUNT(DISTINCT m.source, m.source_id) n, COALESCE(SUM(m.qty),0) kg FROM stock_moves m JOIN items i ON i.id=m.item_id WHERE i.type='raw'")->fetch(),
];
$files = is_dir(BACKUP_DIR) ? glob(BACKUP_DIR . '/factory_*.sql') : [];
rsort($files);

$pageTitle = 'نسخة احتياطية وتصفير';
require __DIR__ . '/inc/header.php';
?>
<h4 class="mb-3">نسخة احتياطية وتصفير</h4>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <h6><i class="bi bi-database-down"></i> نسخة احتياطية</h6>
      <p class="text-muted small mb-2">نسخة كاملة من قاعدة البيانات. ترجّعها من phpMyAdmin ← Import.</p>
      <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="backup">
        <button class="btn btn-primary"><i class="bi bi-download"></i> اعمل نسخة احتياطية دلوقتي</button></form>
      <?php if ($files): ?>
      <table class="table table-sm mb-0"><thead><tr><th>الملف</th><th>الحجم</th><th></th></tr></thead><tbody>
        <?php foreach (array_slice($files, 0, 15) as $f): $n = basename($f); ?>
        <tr><td class="small" dir="ltr"><?= e($n) ?></td><td class="small"><?= number_format(filesize($f) / 1024, 0) ?> KB</td>
          <td><a href="backup.php?download=<?= urlencode($n) ?>" class="btn btn-sm btn-outline-secondary">تحميل</a></td></tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php else: ?><div class="text-muted small">لسه مفيش نسخ احتياطية</div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100 border-danger">
      <h6 class="text-danger"><i class="bi bi-trash3"></i> تصفير بيانات التجربة</h6>
      <p class="text-muted small mb-2">بيتعمل نسخة احتياطية تلقائي قبل التصفير. الأصناف والعملاء والمستخدمين والإعدادات وأرصدة الكونات والكراتين الافتتاحية مش بتتمسح.</p>
      <form method="post" id="resetForm"><?= csrf_field() ?><input type="hidden" name="action" value="reset">
        <?php foreach (RESETS as $k => [$label, $hint]): $c = $counts[$k]; ?>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="reset[]" value="<?= $k ?>" id="r_<?= $k ?>">
          <label class="form-check-label" for="r_<?= $k ?>"><b><?= $label ?></b>
            <span class="badge bg-light text-dark"><?= (int)$c['n'] ?> <?= $k === 'production' ? 'كرتونة — ' . fmt_qty($c['kg'], 1) . ' كيلو' : ($k === 'sales' ? 'فاتورة' : 'حركة — الرصيد ' . fmt_qty($c['kg'], 1) . ' كيلو') ?></span>
            <div class="small text-muted"><?= $hint ?></div></label>
        </div>
        <?php endforeach; ?>
        <div class="row g-2 align-items-end mt-1">
          <div class="col-sm-7"><label class="form-label">كلمة سر المدير للتأكيد</label><input type="password" name="password" class="form-control" required autocomplete="current-password"></div>
          <div class="col-sm-5"><button class="btn btn-danger w-100">نسخة احتياطية + تصفير</button></div>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('resetForm').addEventListener('submit', e => {
  const picked = [...document.querySelectorAll('#resetForm input[name="reset[]"]:checked')].map(c => c.nextElementSibling.querySelector('b').textContent);
  if (!picked.length) { e.preventDefault(); alert('اختار حاجة واحدة على الأقل'); return; }
  if (!confirm('هيتعمل نسخة احتياطية وبعدين هيتمسح: ' + picked.join('، ') + '\nمتأكد؟')) e.preventDefault();
});
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
