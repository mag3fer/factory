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
 * Save one weighed carton/sack: finished yarn in; cones, the carton/sack and the net weight of raw POY out.
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
    if (!$raw) throw new InvalidArgumentException('اختار البوي (الخام اللي على المكنة) — الصافي بيتخصم منه');

    $cones = max(0, (int)$d['cones_count']);
    $gross = round((float)$d['gross'], 3);
    // the cone's own weight comes from the chosen cone item (حسب لون/نوع الكونة); the packing type's cone weight is only a fallback for old types
    $coneW = $cone && (float)$cone['tare_weight'] > 0 ? (float)$cone['tare_weight'] : (float)$pt['cone_weight'];
    if ($cones > 0 && $coneW <= 0) throw new InvalidArgumentException('وزن الكونة مش معروف — اختار لون الكونة (أو حدد وزنها من «الأصناف»)');
    $packW = (float)$pt['pack_weight'];
    $bagW = (float)$pt['bag_weight'];
    $tare = round($cones * $coneW + $packW + $cones * $bagW, 3);
    $net = round($gross - $tare, 3);
    if ($gross <= 0) throw new InvalidArgumentException('الوزن القائم لازم يكون أكبر من صفر');
    if ($net <= 0) throw new InvalidArgumentException('الوزن الصافي طلع صفر أو سالب — راجع الوزن والفوارغ');
    $source = ($d['weight_source'] ?? '') === 'scale' ? 'scale' : 'manual';
    $mixed = !empty($d['mixed']) ? 1 : 0;   // متعدد: the cone count changes from carton to carton
    if ($mixed && $cones < 1) throw new InvalidArgumentException('في المتعدد لازم تكتب عدد الكونات');

    $pdo = db();
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $pdo->beginTransaction();
        try {
            $serial = next_package_serial();
            q('INSERT INTO packages (serial, finished_item_id, raw_item_id, cone_item_id, cones_count, is_mixed, pack_item_id, packing_type_id, cone_weight, pack_weight, bag_weight, tare, gross, net, weight_source, user_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $serial, $fin['id'], $raw['id'] ?? null, $cone['id'] ?? null, $cones, $mixed, $pack['id'] ?? null, $pt['id'],
                $coneW, $packW, $bagW, $tare, $gross, $net, $source, $userId,
            ]);
            $pkgId = (int)$pdo->lastInsertId();
            $now = date('Y-m-d H:i:s');
            $mv = "INSERT INTO stock_moves (item_id, warehouse_id, qty, pieces, source, source_id, move_date) VALUES (?,1,?,?,'package',?,?)";
            q($mv, [$fin['id'], $net, $cones, $pkgId, $now]);
            if ($cone && $cones > 0) q($mv, [$cone['id'], -$cones, -$cones, $pkgId, $now]);
            if ($pack) q($mv, [$pack['id'], -1, -1, $pkgId, $now]);
            q($mv, [$raw['id'], -$net, 0, $pkgId, $now]);   // the yarn's net weight comes out of the raw POY
            $pallet = assign_pallet($pkgId, (int)$fin['id'], $mixed, !empty($d['new_pallet']), (int)$pt['id'], (int)$pt['pallet_size']);
            $pdo->commit();
            return ['id' => $pkgId, 'serial' => $serial, 'tare' => $tare, 'net' => $net, 'pallet' => $pallet];
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
    $palletId = q('SELECT pallet_id FROM packages WHERE id=? FOR UPDATE', [$pkgId])->fetchColumn();
    $st = q("UPDATE packages SET status='void', voided_at=NOW(), void_reason=?, pallet_id=NULL, pallet_seq=NULL WHERE id=? AND status='in_stock'", [$reason, $pkgId]);
    if ($st->rowCount() !== 1) {
        $pdo->rollBack();
        throw new RuntimeException('الكرتونة غير موجودة أو تم بيعها / إلغاؤها');
    }
    q("DELETE FROM stock_moves WHERE source='package' AND source_id=?", [$pkgId]);
    // the freed slot reopens the pallet: the next carton of that count takes the empty number
    if ($palletId) q('UPDATE pallets SET full=0, closed_at=IF(closed=1, closed_at, NULL) WHERE id=?', [$palletId]);
    $pdo->commit();
}

/**
 * Put a freshly saved carton on the open pallet of its count (or start a new one) and give it the
 * lowest free number 1..capacity. Marks the pallet full when the last slot is taken.
 * Must run inside the save transaction (locks the pallet row).
 */
function assign_pallet(int $pkgId, int $itemId, int $mixed = 0, bool $startNew = false, int $ptypeId = 0, int $cap = 20): array
{
    // pallets are per count, type (نظامي/متعدد) and packing type (كرتونة 20 / شكارة 15): they never mix
    $cap = max(1, $cap);
    $key = [$itemId, $mixed, $ptypeId];
    // an open pallet whose cartons were all sold is finished with: close it so the count starts a fresh pallet
    q("UPDATE pallets pl SET pl.closed=1, pl.closed_at=NOW()
       WHERE pl.finished_item_id=? AND pl.is_mixed=? AND pl.packing_type_id=? AND pl.full=0 AND pl.closed=0
         AND EXISTS (SELECT 1 FROM packages p WHERE p.pallet_id=pl.id AND p.status<>'void')
         AND NOT EXISTS (SELECT 1 FROM packages p WHERE p.pallet_id=pl.id AND p.status='in_stock')", $key);
    // the operator chose "start a new pallet": leave the current one as it is (partly filled) and close it
    if ($startNew) q('UPDATE pallets SET closed=1, closed_at=NOW() WHERE finished_item_id=? AND is_mixed=? AND packing_type_id=? AND full=0 AND closed=0', $key);
    $pl = q('SELECT * FROM pallets WHERE finished_item_id=? AND is_mixed=? AND packing_type_id=? AND full=0 AND closed=0 ORDER BY id LIMIT 1 FOR UPDATE', $key)->fetch();
    if (!$pl) {
        q('INSERT INTO pallets (serial, finished_item_id, is_mixed, packing_type_id, capacity) VALUES (?,?,?,?,?)', ['T' . bin2hex(random_bytes(6)), $itemId, $mixed, $ptypeId, $cap]);
        $pid = (int)db()->lastInsertId();
        q("UPDATE pallets SET serial=CONCAT('P', LPAD(id, 5, '0')) WHERE id=?", [$pid]);
        $pl = q('SELECT * FROM pallets WHERE id=?', [$pid])->fetch();
    }
    $used = array_map('intval', q("SELECT pallet_seq FROM packages WHERE pallet_id=? AND status<>'void'", [$pl['id']])->fetchAll(PDO::FETCH_COLUMN));
    $seq = 1;
    while (in_array($seq, $used, true)) $seq++;
    q('UPDATE packages SET pallet_id=?, pallet_seq=? WHERE id=?', [$pl['id'], $seq, $pkgId]);
    $count = count($used) + 1;
    $full = $count >= (int)$pl['capacity'];
    if ($full) q('UPDATE pallets SET full=1, closed_at=NOW() WHERE id=?', [$pl['id']]);
    return ['id' => (int)$pl['id'], 'serial' => $pl['serial'], 'seq' => $seq, 'count' => $count, 'capacity' => (int)$pl['capacity'], 'full' => $full, 'mixed' => (bool)$mixed];
}

/**
 * Raw POY comes on pallets: a standard pallet (36 cones) and a bigger one (42 cones); a container is 44 pallets
 * (38 standard + 6 big = 25,110 kg). Express a weight as pallets + loose cones so a sale or issue can be checked against the labels.
 * $receipt = true tries the exact container split first.
 */
function pallet_text(float $kg, bool $receipt = false): string
{
    $palSmall = (float)setting('raw_pallet_kg', '558');
    $palBig = (float)setting('raw_pallet_big_kg', '651');
    $coneKg = (float)setting('raw_cone_kg', '15.5');
    $palContainer = (int)setting('raw_container_pallets', '44');
    $kg = abs($kg);
    if ($kg < $coneKg / 2) return '';
    if ($receipt) {
        for ($b = 0; $b <= $palContainer; $b++) {
            $a = $palContainer - $b;
            if (abs($a * $palSmall + $b * $palBig - $kg) < 0.5) return "حاوية: $a بالتة عادية × " . fmt_qty($palSmall, 0) . " + $b كبيرة × " . fmt_qty($palBig, 0) . " ($palContainer بالتة)";
        }
    }
    $best = null;   // fewest loose cones, then fewest big pallets
    for ($b = 0; $b * $palBig <= $kg + 0.5 && $b <= 60; $b++) {
        $a = (int)floor(($kg - $b * $palBig + 0.5) / $palSmall);
        if ($a < 0) continue;
        $rest = $kg - $a * $palSmall - $b * $palBig;
        if ($best === null || $rest < $best[2] - 0.001) $best = [$a, $b, $rest];
    }
    [$a, $b, $rest] = $best;
    $parts = [];
    if ($a) $parts[] = "$a عادية";
    if ($b) $parts[] = "$b كبيرة";
    $cones = round($rest / $coneKg * 2) / 2;
    $txt = implode(' + ', $parts) . ($cones > 0 ? ($parts ? ' + ' : '') . rtrim(rtrim(number_format($cones, 1), '0'), '.') . ' كونة' : '');
    return ($rest > 0.5 ? '≈ ' : '') . $txt . (abs($rest) > 0.5 ? ' (تقريبي)' : '');
}
