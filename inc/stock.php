<?php
// Stock posting: every change to quantities goes through these functions so the ledger stays consistent.
declare(strict_types=1);

/** Direction of a doc type: +1 adds stock, -1 removes, 0 = qty already signed (adjust/opening). */
function doc_sign(string $type): int
{
    return ['receipt' => 1, 'issue' => -1, 'adjust' => 0, 'opening' => 0][$type];
}

/**
 * @param array $header ['type','doc_date','party_id','ref_no','notes']
 * @param array $lines  [['item_id','qty','pieces','pallets','price','notes'], ...]
 */
function post_doc(array $header, array $lines, int $userId): int
{
    $type = $header['type'];
    if (!isset(DOC_TYPES[$type])) throw new InvalidArgumentException('نوع مستند غير معروف');
    if (!$lines) throw new InvalidArgumentException('المستند لا يحتوي على أصناف');
    $sign = doc_sign($type);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $num = (int)q('SELECT COALESCE(MAX(number),0)+1 FROM docs WHERE type=? FOR UPDATE', [$type])->fetchColumn();
        q('INSERT INTO docs (type, number, doc_date, party_id, warehouse_id, ref_no, notes, user_id) VALUES (?,?,?,?,1,?,?,?)', [
            $type, $num, $header['doc_date'], $header['party_id'] ?: null, $header['ref_no'] ?: null, $header['notes'] ?: null, $userId,
        ]);
        $docId = (int)$pdo->lastInsertId();
        $moveDate = $header['doc_date'] . ' ' . date('H:i:s');
        foreach ($lines as $l) {
            $item = q('SELECT id, unit FROM items WHERE id=?', [(int)$l['item_id']])->fetch();
            if (!$item) throw new InvalidArgumentException('صنف غير موجود');
            $qty = (float)$l['qty'];
            // piece-counted items (cones, cartons): the count is the quantity
            $pieces = $item['unit'] === 'pcs' ? (int)round($qty) : (int)($l['pieces'] ?? 0);
            if ($sign !== 0 && ($qty <= 0 || $pieces < 0)) throw new InvalidArgumentException('الكمية لازم تكون أكبر من صفر');
            if ($qty == 0 && $pieces == 0) continue;
            q('INSERT INTO doc_lines (doc_id, item_id, pallets, qty, pieces, price, notes) VALUES (?,?,?,?,?,?,?)', [
                $docId, (int)$l['item_id'], ($l['pallets'] ?? '') === '' ? null : (int)$l['pallets'], $qty, $pieces,
                ($l['price'] ?? '') === '' ? null : (float)$l['price'], ($l['notes'] ?? '') ?: null,
            ]);
            $m = $sign === 0 ? 1 : $sign;
            q("INSERT INTO stock_moves (item_id, warehouse_id, qty, pieces, source, source_id, move_date) VALUES (?,1,?,?,'doc',?,?)", [
                (int)$l['item_id'], $m * $qty, $m * $pieces, $docId, $moveDate,
            ]);
        }
        $pdo->commit();
        return $docId;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function void_doc(int $docId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('UPDATE docs SET voided=1 WHERE id=? AND voided=0', [$docId]);
        q("DELETE FROM stock_moves WHERE source='doc' AND source_id=?", [$docId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Next barcode serial: YYMMDD + 4-digit daily sequence, e.g. 2610110007. */
function next_package_serial(): string
{
    $prefix = date('ymd');
    $last = q('SELECT MAX(serial) FROM packages WHERE serial LIKE ? FOR UPDATE', [$prefix . '%'])->fetchColumn();
    $seq = $last ? ((int)substr($last, 6)) + 1 : 1;
    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Save one weighed carton/sack: finished yarn in, cones and the carton/sack out.
 * Tare comes from the packing type (same table as the old station's boxing.txt) and is
 * recomputed here — the browser values are not trusted.
 */
function save_package(array $d, int $userId): array
{
    $fin = q("SELECT * FROM items WHERE id=? AND type='finished' AND active=1", [(int)$d['finished_item_id']])->fetch();
    if (!$fin) throw new InvalidArgumentException('اختر النمرة (الخيط التام)');
    $pt = q('SELECT * FROM packing_types WHERE id=? AND active=1', [(int)($d['packing_type_id'] ?? 0)])->fetch();
    if (!$pt) throw new InvalidArgumentException('اختر نوع التعبئة');
    $cone = !empty($d['cone_item_id']) ? q("SELECT * FROM items WHERE id=? AND type='cone'", [(int)$d['cone_item_id']])->fetch() : null;
    $pack = $pt['pack_item_id'] ? q('SELECT * FROM items WHERE id=?', [(int)$pt['pack_item_id']])->fetch() : null;
    // raw POY: the one picked on the scale, else the raw the finished item is made from
    $rawId = !empty($d['raw_item_id']) ? (int)$d['raw_item_id'] : (int)($fin['raw_item_id'] ?? 0);
    $raw = $rawId ? q("SELECT * FROM items WHERE id=? AND type='raw'", [$rawId])->fetch() : null;
    if ($raw && !$raw['active']) throw new InvalidArgumentException('البوي ده خلصان (موقوف في الأصناف) — مينفعش يتنتج منه');
    // the count must come from this raw: its own raw link, else the count the raw makes (raw spec)
    $rawCount = $raw ? spec_key($raw['spec']) : '';
    $finCount = spec_key($fin['spec']);
    $mismatch = $raw && ($fin['raw_item_id']
        ? (int)$fin['raw_item_id'] !== (int)$raw['id']
        : $rawCount !== '' && $finCount !== '' && $rawCount !== $finCount);
    if ($mismatch)
        throw new InvalidArgumentException('النمرة دي مش من الخام المختار — راجع البوي اللي على المكنة');

    $cones = max(0, (int)$d['cones_count']);
    $gross = round((float)$d['gross'], 3);
    $coneW = (float)$pt['cone_weight'];
    $packW = (float)$pt['pack_weight'];
    $bagW = (float)$pt['bag_weight'];
    $tare = round($cones * $coneW + $packW + $cones * $bagW, 3);
    $net = round($gross - $tare, 3);
    if ($gross <= 0) throw new InvalidArgumentException('الوزن القائم لازم يكون أكبر من صفر');
    if ($net <= 0) throw new InvalidArgumentException('الوزن الصافي طلع صفر أو سالب — راجع الوزن والفوارغ');
    $source = ($d['weight_source'] ?? '') === 'scale' ? 'scale' : 'manual';

    $pdo = db();
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $pdo->beginTransaction();
        try {
            $serial = next_package_serial();
            q('INSERT INTO packages (serial, finished_item_id, raw_item_id, cone_item_id, cones_count, pack_item_id, packing_type_id, cone_weight, pack_weight, bag_weight, tare, gross, net, weight_source, user_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $serial, $fin['id'], $raw['id'] ?? null, $cone['id'] ?? null, $cones, $pack['id'] ?? null, $pt['id'],
                $coneW, $packW, $bagW, $tare, $gross, $net, $source, $userId,
            ]);
            $pkgId = (int)$pdo->lastInsertId();
            $now = date('Y-m-d H:i:s');
            $mv = "INSERT INTO stock_moves (item_id, warehouse_id, qty, pieces, source, source_id, move_date) VALUES (?,1,?,?,'package',?,?)";
            q($mv, [$fin['id'], $net, $cones, $pkgId, $now]);
            if ($cone && $cones > 0) q($mv, [$cone['id'], -$cones, -$cones, $pkgId, $now]);
            if ($pack) q($mv, [$pack['id'], -1, -1, $pkgId, $now]);
            $pdo->commit();
            return ['id' => $pkgId, 'serial' => $serial, 'tare' => $tare, 'net' => $net];
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $duplicate = $ex instanceof PDOException && (int)($ex->errorInfo[1] ?? 0) === 1062;
            if ($duplicate && $attempt < 2) continue; // serial taken by another station at the same moment
            throw $ex;
        }
    }
    throw new RuntimeException('تعذر توليد رقم باركود');
}

function void_package(int $pkgId, string $reason): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $st = q("UPDATE packages SET status='void', voided_at=NOW(), void_reason=? WHERE id=? AND status='in_stock'", [$reason, $pkgId]);
    if ($st->rowCount() !== 1) {
        $pdo->rollBack();
        throw new RuntimeException('الكرتونة غير موجودة أو تم بيعها / إلغاؤها');
    }
    q("DELETE FROM stock_moves WHERE source='package' AND source_id=?", [$pkgId]);
    $pdo->commit();
}
