<?php
// Numbers shown on the scale station: today's production per count, the last hour, and the usual
// net weight per cone for each count (used to warn about a weight that looks wrong).

function scale_stats(): array
{
    $items = q("SELECT i.code, i.name, COUNT(*) n, COALESCE(SUM(p.cones_count),0) cones, COALESCE(SUM(p.net),0) net
                FROM packages p JOIN items i ON i.id=p.finished_item_id
                WHERE p.status<>'void' AND DATE(p.created_at)=CURDATE()
                GROUP BY i.id, i.code, i.name ORDER BY net DESC")->fetchAll();
    $hour = q("SELECT COUNT(*) n, COALESCE(SUM(net),0) net FROM packages
               WHERE status<>'void' AND created_at >= NOW() - INTERVAL 1 HOUR")->fetch();
    $total = ['n' => 0, 'cones' => 0, 'net' => 0.0];
    foreach ($items as $r) {
        $total['n'] += (int)$r['n'];
        $total['cones'] += (int)$r['cones'];
        $total['net'] += (float)$r['net'];
    }
    return ['items' => $items, 'hour' => $hour, 'total' => $total];
}

// finished_item_id => average net kg per cone over its last 40 cartons (only counts with >= 5 samples)
function scale_usual_per_cone(): array
{
    $rows = q("SELECT finished_item_id, net / cones_count AS pc FROM packages
               WHERE status<>'void' AND cones_count > 0 AND created_at >= NOW() - INTERVAL 60 DAY
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

// finished_item_id => the pallet that is filling up now (serial, cartons on it, capacity)
function open_pallets(): array
{
    $out = [];
    foreach (q("SELECT pl.id, pl.serial, pl.finished_item_id, pl.capacity,
                       (SELECT COUNT(*) FROM packages p WHERE p.pallet_id=pl.id AND p.status<>'void') cnt
                FROM pallets pl WHERE pl.full=0 ORDER BY pl.id DESC")->fetchAll() as $r) {
        $out[(int)$r['finished_item_id']] = ['id' => (int)$r['id'], 'serial' => $r['serial'], 'count' => (int)$r['cnt'], 'capacity' => (int)$r['capacity']];
    }
    return $out;
}
