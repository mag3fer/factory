<?php
// Sales invoices, returns, payments and party balances.
// Sign convention for balances: positive = the party owes us.
declare(strict_types=1);

const SALE_TYPES = ['sale' => 'فاتورة بيع', 'return' => 'مرتجع بيع'];
const PAY_TYPES = ['receipt' => 'سند قبض', 'payment' => 'سند دفع'];
const PAY_METHODS = ['cash' => 'نقدي', 'bank' => 'تحويل بنكي', 'cheque' => 'شيك', 'other' => 'أخرى'];

/** The open sale a package currently belongs to (for returns), or null. */
function package_sale(int $pkgId): ?array
{
    $r = q("SELECT s.* FROM sales_packages sp JOIN sales_invoices s ON s.id=sp.invoice_id
            WHERE sp.package_id=? AND s.type='sale' AND s.voided=0 ORDER BY s.id DESC LIMIT 1", [$pkgId])->fetch();
    return $r ?: null;
}

/** Validate a scanned carton for a sale or a return. Returns the package row with item info. */
function lookup_package(string $serial, string $type, int $customerId): array
{
    $p = q('SELECT p.*, i.code, i.name, i.unit FROM packages p JOIN items i ON i.id=p.finished_item_id WHERE p.serial=?', [trim($serial)])->fetch();
    if (!$p) throw new InvalidArgumentException("الباركود $serial مش موجود");
    if ($type === 'sale') {
        if ($p['status'] === 'sold') throw new InvalidArgumentException("الكرتونة $serial اتباعت قبل كده");
        if ($p['status'] === 'void') throw new InvalidArgumentException("الكرتونة $serial ملغية");
    } else {
        if ($p['status'] !== 'sold') throw new InvalidArgumentException("الكرتونة $serial مش مباعة — مينفعش ترجع");
        $sale = package_sale((int)$p['id']);
        if ($customerId && $sale && (int)$sale['customer_id'] !== $customerId) throw new InvalidArgumentException("الكرتونة $serial اتباعت لعميل تاني (فاتورة {$sale['number']})");
        $p['sold_price'] = $sale ? q('SELECT l.price FROM sales_packages sp JOIN sales_lines l ON l.id=sp.line_id WHERE sp.invoice_id=? AND sp.package_id=?', [$sale['id'], $p['id']])->fetchColumn() : null;
    }
    return $p;
}

/**
 * @param array $h       ['type','inv_date','customer_id','notes','discount','paid','pay_method']
 * @param int[] $pkgIds  scanned cartons
 * @param array $prices  item_id => price per unit for scanned cartons
 * @param array $manual  [['item_id','qty','price'], ...] lines without barcodes (waste, cones, ...)
 */
function save_sale(array $h, array $pkgIds, array $prices, array $manual, int $userId): int
{
    $type = $h['type'] === 'return' ? 'return' : 'sale';
    $customer = q("SELECT * FROM parties WHERE id=? AND active=1", [(int)$h['customer_id']])->fetch();
    if (!$customer) throw new InvalidArgumentException('اختر العميل');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$h['inv_date'])) throw new InvalidArgumentException('تاريخ غير صحيح');
    $pkgIds = array_values(array_unique(array_map('intval', $pkgIds)));
    if (!$pkgIds && !$manual) throw new InvalidArgumentException('الفاتورة فاضية — امسح كراتين أو ضيف صنف');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // group cartons by item, re-reading weights from the database
        $groups = [];
        foreach ($pkgIds as $pid) {
            $p = q('SELECT * FROM packages WHERE id=? FOR UPDATE', [$pid])->fetch();
            if (!$p) throw new InvalidArgumentException('كرتونة غير موجودة');
            lookup_package($p['serial'], $type, (int)$customer['id']);  // same checks as when scanned
            $g = &$groups[(int)$p['finished_item_id']];
            $g['ids'][] = $pid;
            $g['qty'] = round(($g['qty'] ?? 0) + (float)$p['net'], 3);
            $g['pieces'] = ($g['pieces'] ?? 0) + (int)$p['cones_count'];
            unset($g);
        }
        $lines = [];
        foreach ($groups as $itemId => $g) {
            $price = (float)($prices[$itemId] ?? -1);
            if ($price < 0) throw new InvalidArgumentException('اكتب سعر كل نمرة');
            $lines[] = ['item_id' => $itemId, 'source' => 'packages', 'count' => count($g['ids']), 'pieces' => $g['pieces'], 'qty' => $g['qty'], 'price' => $price, 'ids' => $g['ids']];
        }
        foreach ($manual as $m) {
            $item = q('SELECT id, unit FROM items WHERE id=? AND active=1', [(int)($m['item_id'] ?? 0)])->fetch();
            $qty = round((float)($m['qty'] ?? 0), 3);
            $price = (float)($m['price'] ?? -1);
            if (!$item || $qty <= 0) throw new InvalidArgumentException('صنف يدوي ناقص بيانات (الصنف والكمية)');
            if ($price < 0) throw new InvalidArgumentException('اكتب سعر الصنف اليدوي');
            $lines[] = ['item_id' => (int)$item['id'], 'source' => 'manual', 'count' => 0, 'pieces' => $item['unit'] === 'pcs' ? (int)round($qty) : 0, 'qty' => $qty, 'price' => $price, 'ids' => []];
        }
        $subtotal = 0.0;
        foreach ($lines as &$l) { $l['amount'] = round($l['qty'] * $l['price'], 2); $subtotal += $l['amount']; }
        unset($l);
        $subtotal = round($subtotal, 2);
        $discount = round(max(0, (float)($h['discount'] ?? 0)), 2);
        if ($discount > $subtotal) throw new InvalidArgumentException('الخصم أكبر من الإجمالي');
        $total = round($subtotal - $discount, 2);

        $num = (int)q('SELECT COALESCE(MAX(number),0)+1 FROM sales_invoices WHERE type=? FOR UPDATE', [$type])->fetchColumn();
        q('INSERT INTO sales_invoices (type, number, inv_date, customer_id, subtotal, discount, total, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$type, $num, $h['inv_date'], $customer['id'], $subtotal, $discount, $total, trim((string)($h['notes'] ?? '')) ?: null, $userId]);
        $invId = (int)$pdo->lastInsertId();
        $sign = $type === 'sale' ? -1 : 1;   // stock out on sale, back in on return
        $moveDate = $h['inv_date'] . ' ' . date('H:i:s');
        foreach ($lines as $l) {
            q('INSERT INTO sales_lines (invoice_id, item_id, source, packages_count, pieces, qty, price, amount) VALUES (?,?,?,?,?,?,?,?)',
                [$invId, $l['item_id'], $l['source'], $l['count'], $l['pieces'], $l['qty'], $l['price'], $l['amount']]);
            $lineId = (int)$pdo->lastInsertId();
            q("INSERT INTO stock_moves (item_id, warehouse_id, qty, pieces, source, source_id, move_date) VALUES (?,1,?,?,'sale',?,?)",
                [$l['item_id'], $sign * $l['qty'], $sign * $l['pieces'], $invId, $moveDate]);
            foreach ($l['ids'] as $pid) {
                q('INSERT INTO sales_packages (invoice_id, package_id, line_id) VALUES (?,?,?)', [$invId, $pid, $lineId]);
                q('UPDATE packages SET status=? WHERE id=?', [$type === 'sale' ? 'sold' : 'in_stock', $pid]);
            }
        }
        $paid = round((float)($h['paid'] ?? 0), 2);
        if ($paid > 0) {
            insert_payment([
                'type' => $type === 'sale' ? 'receipt' : 'payment', 'pay_date' => $h['inv_date'], 'party_id' => $customer['id'], 'amount' => $paid,
                'method' => $h['pay_method'] ?? 'cash', 'notes' => SALE_TYPES[$type] . ' رقم ' . $num, 'invoice_id' => $invId,
            ], $userId);
        }
        $pdo->commit();
        return $invId;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Void an invoice: stock and carton status go back, linked payments are voided. */
function void_sale(int $invId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $inv = q('SELECT * FROM sales_invoices WHERE id=? FOR UPDATE', [$invId])->fetch();
        if (!$inv || $inv['voided']) throw new RuntimeException('الفاتورة غير موجودة أو ملغية');
        $expect = $inv['type'] === 'sale' ? 'sold' : 'in_stock';
        foreach (q('SELECT p.id, p.serial, p.status FROM sales_packages sp JOIN packages p ON p.id=sp.package_id WHERE sp.invoice_id=? FOR UPDATE', [$invId])->fetchAll() as $p) {
            if ($p['status'] !== $expect) throw new RuntimeException("مينفعش تلغي: الكرتونة {$p['serial']} اتغيرت حالتها بعد الفاتورة دي");
            if ($inv['type'] === 'sale') {
                $later = q("SELECT COUNT(*) FROM sales_packages sp JOIN sales_invoices s ON s.id=sp.invoice_id WHERE sp.package_id=? AND s.id>? AND s.voided=0", [$p['id'], $invId])->fetchColumn();
                if ($later) throw new RuntimeException("مينفعش تلغي: الكرتونة {$p['serial']} عليها مرتجع أو فاتورة بعدها");
            }
            q('UPDATE packages SET status=? WHERE id=?', [$inv['type'] === 'sale' ? 'in_stock' : 'sold', $p['id']]);
        }
        q("DELETE FROM stock_moves WHERE source='sale' AND source_id=?", [$invId]);
        q('UPDATE payments SET voided=1 WHERE invoice_id=?', [$invId]);
        q('UPDATE sales_invoices SET voided=1 WHERE id=?', [$invId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Insert a receipt/payment (caller handles the transaction if needed). */
function insert_payment(array $p, int $userId): int
{
    $type = $p['type'] === 'payment' ? 'payment' : 'receipt';
    $amount = round((float)$p['amount'], 2);
    if ($amount <= 0) throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر');
    if (!isset(PAY_METHODS[$p['method'] ?? ''])) $p['method'] = 'cash';
    if (!q('SELECT id FROM parties WHERE id=?', [(int)$p['party_id']])->fetch()) throw new InvalidArgumentException('اختر العميل / المورد');
    $num = (int)q('SELECT COALESCE(MAX(number),0)+1 FROM payments WHERE type=? FOR UPDATE', [$type])->fetchColumn();
    q('INSERT INTO payments (type, number, pay_date, party_id, amount, method, ref_no, invoice_id, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?,?)', [
        $type, $num, $p['pay_date'], (int)$p['party_id'], $amount, $p['method'], ($p['ref_no'] ?? '') ?: null, $p['invoice_id'] ?? null, ($p['notes'] ?? '') ?: null, $userId,
    ]);
    return (int)db()->lastInsertId();
}

/** Balance of a party up to (and including) $asOf. Positive = they owe us. */
function party_balance(int $partyId, ?string $asOf = null): float
{
    $asOf = $asOf ?? '9999-12-31';
    $open = (float)q('SELECT opening_balance FROM parties WHERE id=?', [$partyId])->fetchColumn();
    $s = q("SELECT COALESCE(SUM(CASE WHEN type='sale' THEN total ELSE -total END),0) FROM sales_invoices WHERE customer_id=? AND voided=0 AND inv_date<=?", [$partyId, $asOf])->fetchColumn();
    $p = q("SELECT COALESCE(SUM(CASE WHEN type='receipt' THEN -amount ELSE amount END),0) FROM payments WHERE party_id=? AND voided=0 AND pay_date<=?", [$partyId, $asOf])->fetchColumn();
    return round($open + (float)$s + (float)$p, 2);
}

/** Statement lines between two dates: [date, kind, ref, description, debit, credit, link]. */
function party_ledger(int $partyId, string $from, string $to): array
{
    $rows = [];
    foreach (q("SELECT * FROM sales_invoices WHERE customer_id=? AND voided=0 AND inv_date BETWEEN ? AND ? ORDER BY inv_date, id", [$partyId, $from, $to])->fetchAll() as $s) {
        $kg = (float)q('SELECT COALESCE(SUM(qty),0) FROM sales_lines WHERE invoice_id=?', [$s['id']])->fetchColumn();
        $rows[] = ['date' => $s['inv_date'], 'sort' => $s['created_at'], 'kind' => SALE_TYPES[$s['type']], 'ref' => $s['number'],
            'desc' => fmt_qty($kg, 1) . ' كيلو' . ($s['notes'] ? ' — ' . $s['notes'] : ''),
            'debit' => $s['type'] === 'sale' ? (float)$s['total'] : 0, 'credit' => $s['type'] === 'return' ? (float)$s['total'] : 0, 'link' => 'sale_view.php?id=' . $s['id']];
    }
    foreach (q("SELECT * FROM payments WHERE party_id=? AND voided=0 AND pay_date BETWEEN ? AND ? ORDER BY pay_date, id", [$partyId, $from, $to])->fetchAll() as $p) {
        $rows[] = ['date' => $p['pay_date'], 'sort' => $p['created_at'], 'kind' => PAY_TYPES[$p['type']], 'ref' => $p['number'],
            'desc' => PAY_METHODS[$p['method']] . ($p['ref_no'] ? ' ' . $p['ref_no'] : '') . ($p['notes'] ? ' — ' . $p['notes'] : ''),
            'debit' => $p['type'] === 'payment' ? (float)$p['amount'] : 0, 'credit' => $p['type'] === 'receipt' ? (float)$p['amount'] : 0, 'link' => 'payments.php?id=' . $p['id']];
    }
    usort($rows, fn($a, $b) => [$a['date'], $a['sort']] <=> [$b['date'], $b['sort']]);
    return $rows;
}
