<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('store');

$type = $_GET['type'] ?? '';
$sql = "SELECT d.*, p.name party, us.full_name usr, COUNT(l.id) nlines, COALESCE(SUM(l.qty),0) total
        FROM docs d LEFT JOIN parties p ON p.id=d.party_id JOIN users us ON us.id=d.user_id LEFT JOIN doc_lines l ON l.doc_id=d.id";
$params = [];
if (isset(DOC_TYPES[$type])) { $sql .= ' WHERE d.type=?'; $params[] = $type; }
$docs = q($sql . ' GROUP BY d.id ORDER BY d.doc_date DESC, d.id DESC LIMIT 300', $params)->fetchAll();

$pageTitle = 'المستندات';
require __DIR__ . '/inc/header.php';
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-2">
  <h4 class="mb-0 me-3">المستندات</h4>
  <a href="doc_new.php?type=receipt" class="btn btn-primary">+ استلام خام / مشتريات</a>
  <a href="doc_new.php?type=issue" class="btn btn-warning">+ صرف للتشغيل</a>
  <a href="doc_new.php?type=adjust" class="btn btn-outline-secondary">+ تسوية</a>
  <?php if ($u['role'] === 'admin'): ?><a href="doc_new.php?type=opening" class="btn btn-outline-secondary">+ رصيد افتتاحي</a><?php endif; ?>
</div>
<ul class="nav nav-pills mb-2">
  <li class="nav-item"><a class="nav-link<?= $type === '' ? ' active' : '' ?>" href="docs.php">الكل</a></li>
  <?php foreach (DOC_TYPES as $k => $l): ?><li class="nav-item"><a class="nav-link<?= $type === $k ? ' active' : '' ?>" href="?type=<?= $k ?>"><?= $l ?></a></li><?php endforeach; ?>
</ul>
<div class="card p-0">
<table class="table table-sm table-hover mb-0">
  <thead class="table-light"><tr><th>النوع</th><th>رقم</th><th>التاريخ</th><th>المورد</th><th>رقم المورد</th><th>سطور</th><th>إجمالي الكمية</th><th>ملاحظات</th><th>بواسطة</th><th></th></tr></thead>
  <?php foreach ($docs as $d): ?>
    <tr class="<?= $d['voided'] ? 'text-decoration-line-through text-muted' : '' ?>">
      <td><?= DOC_TYPES[$d['type']] ?></td><td><?= (int)$d['number'] ?></td><td><?= e($d['doc_date']) ?></td><td><?= e($d['party']) ?></td><td><?= e($d['ref_no']) ?></td>
      <td><?= (int)$d['nlines'] ?></td><td class="num"><?= fmt_qty($d['total']) ?></td><td><?= e($d['notes']) ?></td><td><?= e($d['usr']) ?></td>
      <td><a class="btn btn-sm btn-outline-secondary" href="doc_view.php?id=<?= $d['id'] ?>">عرض</a></td>
    </tr>
  <?php endforeach; if (!$docs): ?><tr><td colspan="10" class="text-muted">مفيش مستندات</td></tr><?php endif; ?>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
