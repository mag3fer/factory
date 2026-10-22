<?php
// Workers, attendance, payroll and the personal ledger (what the factory owes each person). Balance = earned − advances/deductions − paid.
declare(strict_types=1);

const SHIFTS = ['morning' => 'صباحية', 'evening' => 'مسائية', 'night' => 'ليل'];
const WORKER_ROLES = ['worker' => 'عامل', 'goods_driver' => 'سائق نقل بضاعة', 'bus_driver' => 'سائق ميكروباص العمال'];
const PAY_BASIS = ['monthly' => 'شهري', 'daily' => 'يومية', 'trip' => 'بالمشوار'];
const ATT_STATUS = ['present' => 'حاضر', 'half' => 'نص يوم', 'rest' => 'راحة', 'absent' => 'غايب'];
const LEDGER_KINDS = ['wage' => 'مرتب', 'bonus' => 'مكافأة / إضافي', 'trip' => 'مشاوير', 'advance' => 'سلفة', 'deduction' => 'خصم', 'payment' => 'صرف'];
const TRIP_KINDS = ['goods' => 'توصيل بضاعة لعميل', 'bus_in' => 'ميكروباص — جاي بالعمال', 'bus_out' => 'ميكروباص — رايح بالعمال', 'bus_empty' => 'ميكروباص — جاي وراح من غير ركاب (بسعر أعلى)', 'other' => 'مشوار خاص (المبلغ بتكتبه بإيدك)'];
/** Trip kinds that have a fixed price in trip_rates (the special trip has none). */
function rated_trip_kinds(): array { return array_diff_key(TRIP_KINDS, ['other' => 1]); }

/** +1 when the entry increases what we owe the person, −1 when it reduces it. */
function hr_sign(string $kind): int
{
    return in_array($kind, ['wage', 'bonus', 'trip'], true) ? 1 : -1;
}

/** @return array<int,float> worker id => balance (positive = we owe them, negative = they owe us, e.g. an advance not yet deducted) */
function hr_balances(?string $asOf = null): array
{
    $rows = q("SELECT worker_id, SUM(CASE WHEN kind IN ('wage','bonus','trip') THEN amount ELSE -amount END) b
               FROM hr_ledger WHERE voided=0 AND entry_date<=? GROUP BY worker_id", [$asOf ?? '9999-12-31'])->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map('floatval', $rows);
}

function hr_balance(int $workerId): float
{
    return hr_balances()[$workerId] ?? 0.0;
}

function ledger_add(array $e, int $userId): int
{
    $kind = (string)($e['kind'] ?? '');
    if (!isset(LEDGER_KINDS[$kind])) throw new InvalidArgumentException('نوع غير معروف');
    $amount = round((float)($e['amount'] ?? 0), 2);
    if ($amount <= 0) throw new InvalidArgumentException('المبلغ لازم يكون أكبر من صفر');
    $date = (string)($e['entry_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('تاريخ غير صحيح');
    if (!q('SELECT id FROM workers WHERE id=?', [(int)$e['worker_id']])->fetch()) throw new InvalidArgumentException('اختر العامل');
    q('INSERT INTO hr_ledger (worker_id, entry_date, kind, amount, period_from, period_to, ref_id, notes, user_id) VALUES (?,?,?,?,?,?,?,?,?)', [
        (int)$e['worker_id'], $date, $kind, $amount, $e['period_from'] ?? null, $e['period_to'] ?? null, $e['ref_id'] ?? null,
        mb_substr(trim((string)($e['notes'] ?? '')), 0, 255) ?: null, $userId,
    ]);
    return (int)db()->lastInsertId();
}

/** Attendance totals between two dates: worker id => [present, half, absent, rest, ot, ov_sum (typed day amounts), ov_days (days those amounts replace)]. */
function attendance_totals(string $from, string $to): array
{
    $out = [];
    foreach (q("SELECT worker_id,
                  SUM(status='present') p, SUM(status='half') h, SUM(status='absent') a, SUM(status='rest') r,
                  COALESCE(SUM(CASE WHEN status IN ('present','half') THEN overtime_hours END),0) ot,
                  COALESCE(SUM(CASE WHEN status IN ('present','half') AND day_amount IS NOT NULL THEN day_amount END),0) ovs,
                  COALESCE(SUM(CASE WHEN day_amount IS NOT NULL THEN (CASE status WHEN 'present' THEN 1 WHEN 'half' THEN 0.5 ELSE 0 END) END),0) ovd
                FROM attendance WHERE work_date BETWEEN ? AND ? GROUP BY worker_id", [$from, $to])->fetchAll() as $r) {
        $out[(int)$r['worker_id']] = ['present' => (int)$r['p'], 'half' => (int)$r['h'], 'absent' => (int)$r['a'], 'rest' => (int)$r['r'], 'ot' => (float)$r['ot'],
                                      'ov_sum' => (float)$r['ovs'], 'ov_days' => (float)$r['ovd']];
    }
    return $out;
}

/** Whether [$from,$to] is exactly one calendar month (a monthly salary is then paid in full). */
function is_full_month(string $from, string $to): bool
{
    return $from === date('Y-m-01', strtotime($from)) && $to === date('Y-m-t', strtotime($from));
}

/**
 * Wage of one worker for a period.
 * daily: (present days + half days/2) × daily rate (a typed amount for a day replaces that day's rate);
 * monthly: the salary (pro-rata days/30 when the period is not a full calendar month or the worker started inside it) minus absent days × salary/30
 *   (rest days — "راحة" — are never deducted);  overtime hours × the worker's hourly overtime rate (0 = not paid);
 * per-trip drivers earn through their trips, not here.
 */
function wage_calc(array $w, string $from, string $to, array $att): array
{
    $a = $att[(int)$w['id']] ?? ['present' => 0, 'half' => 0, 'absent' => 0, 'rest' => 0, 'ot' => 0.0, 'ov_sum' => 0.0, 'ov_days' => 0.0];
    $days = $a['present'] + 0.5 * $a['half'];
    $rate = (float)$w['rate'];
    // a worker earns only from the day he started
    $effFrom = (!empty($w['start_date']) && $w['start_date'] > $from) ? $w['start_date'] : $from;
    $started = $effFrom <= $to;
    $base = 0.0; $absDed = 0.0;
    if ($w['pay_type'] === 'daily') {
        $base = ($days - $a['ov_days']) * $rate + $a['ov_sum'];
    } elseif ($w['pay_type'] === 'monthly' && $started) {
        $periodDays = (int)((strtotime($to) - strtotime($effFrom)) / 86400) + 1;
        $base = is_full_month($effFrom, $to) ? $rate : $rate * min(30, $periodDays) / 30;
        // days off (absent + rest + half-days/2) come out of the worker's allowed rest days; only the excess is deducted (salary/30 per day)
        $off = $a['absent'] + $a['rest'] + 0.5 * $a['half'];
        $allowed = (int)$w['rest_days'] * max(1, (int)round($periodDays / 30));
        $excess = max(0.0, $off - $allowed);
        $absDed = $excess * $rate / 30;
    }
    $otPay = $w['pay_type'] === 'trip' ? 0.0 : $a['ot'] * (float)$w['ot_rate'];
    return ['days' => $days, 'absent' => $a['absent'], 'half' => $a['half'], 'rest' => $a['rest'], 'ot' => $a['ot'], 'base' => round($base, 2), 'abs_ded' => round($absDed, 2),
            'off' => $off ?? 0.0, 'allowed' => $allowed ?? 0, 'excess' => $excess ?? 0.0,
            'ot_pay' => round($otPay, 2), 'wage' => round(max(0, $base - $absDed + $otPay), 2), 'eff_from' => $effFrom, 'started' => $started];
}

/**
 * What the person has earned so far but that is not yet posted to the ledger: from the day after the last posted wage
 * (or the start / first attendance) up to $asOf, month by month (a partial month is pro-rata). Per-trip drivers have nothing here.
 * @return array{wage:float, from:?string, to:string}
 */
function accrued_wage(array $w, string $asOf): array
{
    static $att = [];
    if ($w['pay_type'] === 'trip') return ['wage' => 0.0, 'from' => null, 'to' => $asOf];
    $last = q("SELECT MAX(period_to) FROM hr_ledger WHERE worker_id=? AND kind='wage' AND voided=0", [(int)$w['id']])->fetchColumn();
    if ($last) $from = date('Y-m-d', strtotime($last . ' +1 day'));
    else {
        $first = q('SELECT MIN(work_date) FROM attendance WHERE worker_id=?', [(int)$w['id']])->fetchColumn();
        $from = $w['start_date'] ?: ($first ?: date('Y-m-01', strtotime($asOf)));
    }
    if ($from > $asOf) return ['wage' => 0.0, 'from' => $from, 'to' => $asOf];
    $total = 0.0;
    for ($segFrom = $from; $segFrom <= $asOf; $segFrom = date('Y-m-d', strtotime($segTo . ' +1 day'))) {
        $segTo = min($asOf, date('Y-m-t', strtotime($segFrom)));
        $att["$segFrom|$segTo"] ??= attendance_totals($segFrom, $segTo);
        $total += wage_calc($w, $segFrom, $segTo, $att["$segFrom|$segTo"])['wage'];
    }
    return ['wage' => round($total, 2), 'from' => $from, 'to' => $asOf];
}

/** Payroll lines for a period: every worker that earns a wage, with the calculation and whether the period is already posted. */
function payroll_rows(string $from, string $to): array
{
    $att = attendance_totals($from, $to);
    $ids = array_keys($att);
    $workers = q('SELECT * FROM workers WHERE active=1 OR id IN (' . ($ids ? implode(',', array_map('intval', $ids)) : '0') . ') ORDER BY role, name')->fetchAll();
    $bal = hr_balances();
    $posted = [];
    foreach (q("SELECT worker_id, SUM(amount) amt, MIN(period_from) pf, MAX(period_to) pt FROM hr_ledger
                WHERE kind='wage' AND voided=0 AND period_from<=? AND period_to>=? GROUP BY worker_id", [$to, $from])->fetchAll() as $r) $posted[(int)$r['worker_id']] = $r;
    $rows = [];
    foreach ($workers as $w) {
        $c = wage_calc($w, $from, $to, $att);
        $rows[] = $w + $c + ['posted' => $posted[(int)$w['id']] ?? null, 'balance' => $bal[(int)$w['id']] ?? 0.0];
    }
    return $rows;
}

/** Posts the wage of each worker for the period into their ledger (skips workers already posted for an overlapping period or earning nothing). */
function post_payroll(string $from, string $to, int $userId): array
{
    $done = 0; $skipped = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (payroll_rows($from, $to) as $r) {
            if ($r['pay_type'] === 'trip') continue;
            if ($r['posted']) { $skipped[] = $r['name']; continue; }
            if ($r['wage'] <= 0) continue;
            $parts = [];
            if ($r['pay_type'] === 'daily') $parts[] = fmt_qty($r['days'], 1) . ' يوم';
            if ($r['ot'] > 0) $parts[] = fmt_qty($r['ot'], 1) . ' ساعة إضافي';
            if ($r['pay_type'] === 'monthly' && $r['off'] > 0) $parts[] = 'غياب/راحة ' . fmt_qty($r['off'], 1) . ' من ' . (int)$r['allowed'];
            if ($r['abs_ded'] > 0) $parts[] = 'خصم ' . fmt_qty($r['excess'], 1) . ' يوم زيادة عن الراحات = ' . fmt_qty($r['abs_ded'], 2);
            ledger_add(['worker_id' => $r['id'], 'kind' => 'wage', 'amount' => $r['wage'], 'entry_date' => $to, 'period_from' => $from, 'period_to' => $to,
                        'notes' => 'مرتب من ' . $from . ' إلى ' . $to . ($parts ? ' (' . implode('، ', $parts) . ')' : '')], $userId);
            $done++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['posted' => $done, 'skipped' => $skipped];
}

function money(float $n): string
{
    return fmt_qty($n, 2);
}
