<?php
// Numbers shown on the scale station: today's production per count, the last hour, and the usual
// net weight per cone for each count (used to warn about a weight that looks wrong).

function scale_stats(): array
{
    // one line per count, type (نظامي / متعدد) and packing (كرتونة / شكارة)
    $items = q("SELECT i.code, i.name, p.is_mixed mixed, (pi.name LIKE '%شكار%') sack, COUNT(*) n, COALESCE(SUM(p.cones_count),0) cones, COALESCE(SUM(p.net),0) net
                FROM packages p JOIN items i ON i.id=p.finished_item_id LEFT JOIN items pi ON pi.id=p.pack_item_id
                WHERE p.status<>'void' AND DATE(p.created_at)=CURDATE()
                GROUP BY i.id, i.code, i.name, p.is_mixed, (pi.name LIKE '%شكار%') ORDER BY net DESC")->fetchAll();
    $hour = q("SELECT COUNT(*) n, COALESCE(SUM(p.net),0) net, COALESCE(SUM(pi.name LIKE '%شكار%'),0) sacks FROM packages p LEFT JOIN items pi ON pi.id=p.pack_item_id
               WHERE p.status<>'void' AND p.created_at >= NOW() - INTERVAL 1 HOUR")->fetch();
    $hour['cartons'] = (int)$hour['n'] - (int)$hour['sacks'];
    $total = ['n' => 0, 'cones' => 0, 'net' => 0.0, 'sacks' => 0];
    foreach ($items as $r) {
        $total['n'] += (int)$r['n'];
        if ($r['sack']) $total['sacks'] += (int)$r['n'];
        $total['cones'] += (int)$r['cones'];
        $total['net'] += (float)$r['net'];
    }
    $total['cartons'] = $total['n'] - $total['sacks'];
    return ['items' => $items, 'hour' => $hour, 'total' => $total, 'warehouse' => warehouse_stock()];
}

// "5 كرتونة" / "3 شكارة" / "5 كرتونة + 3 شكارة" — cartons and sacks are never lumped together
function count_text(int $cartons, int $sacks): string
{
    $parts = [];
    if ($cartons > 0 || $sacks === 0) $parts[] = $cartons . ' كرتونة';
    if ($sacks > 0) $parts[] = $sacks . ' شكارة';
    return implode(' + ', $parts);
}

// Finished goods on hand right now (cartons with status in_stock), per count, for the station's stock card.
function warehouse_stock(): array
{
    // one line per count, type (نظامي / متعدد) and packing (كرتونة / شكارة)
    $items = q("SELECT i.id, i.code, i.name, p.is_mixed mixed, COALESCE(p.packing_type_id,0) ptype, MAX(pt.name) pack,
                       COUNT(*) n, COALESCE(SUM(p.net),0) kg, COALESCE(SUM(p.cones_count),0) cones,
                       COUNT(DISTINCT p.pallet_id) pallets
                FROM packages p JOIN items i ON i.id=p.finished_item_id LEFT JOIN packing_types pt ON pt.id=p.packing_type_id
                WHERE p.status='in_stock' GROUP BY i.id, i.code, i.name, p.is_mixed, COALESCE(p.packing_type_id,0) ORDER BY kg DESC")->fetchAll();
    $open = open_pallets();
    $total = ['n' => 0, 'kg' => 0.0, 'cones' => 0, 'pallets' => 0];
    foreach ($items as &$r) {
        $r['n'] = (int)$r['n']; $r['kg'] = round((float)$r['kg'], 2); $r['cones'] = (int)$r['cones']; $r['pallets'] = (int)$r['pallets']; $r['mixed'] = (int)$r['mixed'];
        $k = (int)$r['id'] . ':' . $r['mixed'] . ':' . (int)$r['ptype'];
        $r['open'] = isset($open[$k]) ? $open[$k]['in_stock'] : 0;   // cartons on the pallet that is still filling
        $r['cap'] = isset($open[$k]) ? $open[$k]['capacity'] : 0;
        $r['sack'] = (int)(mb_strpos((string)$r['pack'], 'شكارة') === 0);
        $total['n'] += $r['n']; $total['kg'] += $r['kg']; $total['cones'] += $r['cones']; $total['pallets'] += $r['pallets'];
    }
    unset($r);
    $total['kg'] = round($total['kg'], 2);
    $total['sacks'] = array_sum(array_map(fn($r) => $r['sack'] ? $r['n'] : 0, $items));   // sacks counted apart from cartons
    $total['cartons'] = $total['n'] - $total['sacks'];
    return ['items' => $items, 'total' => $total];
}

// finished_item_id => average net kg per cone over its last 40 cartons (only counts with >= 5 samples)
function scale_usual_per_cone(): array
{
    $rows = q("SELECT finished_item_id, net / cones_count AS pc FROM packages
               WHERE status<>'void' AND is_mixed=0 AND cones_count > 0 AND created_at >= NOW() - INTERVAL 60 DAY
               ORDER BY id DESC LIMIT 600")->fetchAll();
    $by = [];
    foreach ($rows as $r) {
        $k = (int)$r['finished_item_id'];
        if (count($by[$k] ?? []) < 40) $by[$k][] = (float)$r['pc'];
    }
    $out = [];
    foreach ($by as $k => $v) {
        if (count($v) >= 5) $out[$k] = round(array_sum($v) / count($v), 4);
    }
    return $out;
}

// "itemId:mixed" => the pallet that is filling up now for that count and type (serial, cartons on it,
// how many are still in the warehouse, capacity). A pallet whose cartons were all sold is not offered
// for continuing (the next carton starts a fresh pallet).
function open_pallets(): array
{
    $out = [];
    foreach (q("SELECT pl.id, pl.serial, pl.finished_item_id, pl.is_mixed, COALESCE(pl.packing_type_id,0) ptype, pl.capacity,
                       (SELECT COUNT(*) FROM packages p WHERE p.pallet_id=pl.id AND p.status<>'void') cnt,
                       (SELECT COUNT(*) FROM packages p WHERE p.pallet_id=pl.id AND p.status='in_stock') in_stock
                FROM pallets pl WHERE pl.full=0 AND pl.closed=0 ORDER BY pl.id DESC")->fetchAll() as $r) {
        if ((int)$r['cnt'] > 0 && (int)$r['in_stock'] === 0) continue;
        $k = (int)$r['finished_item_id'] . ':' . (int)$r['is_mixed'] . ':' . (int)$r['ptype'];
        if (isset($out[$k])) continue;
        $out[$k] = ['id' => (int)$r['id'], 'serial' => $r['serial'], 'count' => (int)$r['cnt'], 'in_stock' => (int)$r['in_stock'], 'capacity' => (int)$r['capacity'], 'mixed' => (int)$r['is_mixed']];
    }
    return $out;
}
