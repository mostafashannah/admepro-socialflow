<?php
// One-off: recalculates base_salary/vacation_overage_days/net_amount on
// already-generated PENDING payroll_runs rows using the same day-by-day
// proration the cron now uses (mid-month salary raises, start_date,
// termination_date, and probation_salary while still inside the
// probation period), since rows generated before those fixes existed used
// whatever the salary is today for the whole month and never accounted
// for probation at all. Also re-pulls vacation_days_used/vacation_days_total
// fresh from team_members instead of trusting the overage baked into the
// row at generation time — run recompute-vacation-and-attendance.php first
// if vacation_days_used may itself have been wrong. Never touches
// approved/rejected rows.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dayRate = 30;
$parseNum = function($v) { return (float)preg_replace('/[^0-9.]/', '', (string)$v); };
// Unapproved absences (status absent, no clock-in, not covered by approved
// leave) in the first 3 months, up to the termination date, are deducted
// from a terminated member's final pay.
function firstThreeMonthsAbsenceDeduction($pdo, $memberId, $startDate, $termDate, $fullSalary, $probationSalary) {
    if (!$startDate || !$termDate) return 0.0;
    $end3 = date('Y-m-d', strtotime($startDate . ' +3 months'));
    $q = $pdo->prepare("SELECT work_date, check_in, status FROM attendance_records WHERE team_member_id = ? AND work_date >= ? AND work_date < ? AND work_date <= ?");
    $q->execute([$memberId, $startDate, $end3, $termDate]);
    $lv = $pdo->prepare("SELECT start_date, end_date FROM leave_requests WHERE team_member_id = ? AND LOWER(status) = 'approved'");
    $lv->execute([$memberId]);
    $leaves = $lv->fetchAll(PDO::FETCH_ASSOC);
    $days = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $d = substr($a['work_date'], 0, 10);
        if (strtolower((string)$a['status']) !== 'absent' || !empty($a['check_in'])) continue;
        $days[$d] = 1;
    }
    // No leave credit in the first 3 months: approved leave days are deducted too (Fri/Sat = weekend).
    foreach ($leaves as $l) {
        $t = strtotime($l['start_date']); $e = strtotime($l['end_date'] ?: $l['start_date']);
        for (; $t <= $e; $t += 86400) {
            $d = date('Y-m-d', $t); $w = (int)date('w', $t);
            if ($w === 5 || $w === 6 || $d < $startDate || $d >= $end3 || $d > $termDate) continue;
            $days[$d] = 1;
        }
    }
    $rate = ($probationSalary > 0 ? $probationSalary : $fullSalary) / 30;
    // Late-policy hours (late_deducted=1) in the first 3 months: no leave
    // credit exists yet, so they are charged as money (8h = 1 day).
    $rs = $pdo->query("SELECT attendance_rules FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $rules = $rs && !empty($rs['attendance_rules']) ? json_decode($rs['attendance_rules'], true) : [];
    $rules = is_array($rules) ? $rules : [];
    $hrsPer = floatval($rules['lateDeductHours'] ?? 2);
    $lq = $pdo->prepare("SELECT COUNT(*) FROM attendance_records WHERE team_member_id = ? AND late_deducted = 1 AND work_date >= ? AND work_date < ? AND work_date <= ?");
    $lq->execute([$memberId, $startDate, $end3, $termDate]);
    $lateHours = intval($lq->fetchColumn()) * $hrsPer / max(1, intval($rules['lateTriggerCount'] ?? 1));
    return round(count($days) * $rate + ($lateHours / 8) * $rate, 2);
}

function proratedMonthlySalary($currentSalary, $events, $year, $month, $startDay, $endDay, $daysInMonth, $probationEndDate, $probationSalary) {
    $total = 0.0;
    for ($d = $startDay; $d <= $endDay; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $rate = $currentSalary;
        if ($probationEndDate && $dateStr < $probationEndDate) {
            $rate = $probationSalary;
        } else {
            $applicable = null;
            foreach ($events as $e) { if ($e['date'] <= $dateStr) $applicable = $e; }
            if ($applicable) $rate = $applicable['rate'];
            elseif (count($events) > 0 && $events[0]['prevRate'] > 0) $rate = $events[0]['prevRate'];
        }
        $total += $rate / $daysInMonth;
    }
    return round($total, 2);
}

$rows = $pdo->query(
    "SELECT pr.id, pr.termination_deduction, pr.overage_status, pr.team_member_id, pr.member_name, pr.salary_month,
            tm.role, tm.salary, tm.probation_salary, tm.probation_months, tm.termination_date, tm.employment_type, tm.attendance_policy_exempt,
            tm.vacation_days_used, tm.vacation_days_total,
            COALESCE(tm.start_date, DATE(tm.created_at)) AS start_date
     FROM payroll_runs pr JOIN team_members tm ON tm.id = pr.team_member_id
     WHERE pr.status = 'pending'"
)->fetchAll(PDO::FETCH_ASSOC);

$raiseEventsStmt = $pdo->prepare(
    "SELECT effective_date, previous_value, new_value FROM team_member_events WHERE team_member_id = ? AND event_type = 'salary_raise' AND effective_date IS NOT NULL ORDER BY effective_date ASC"
);
$update = $pdo->prepare("UPDATE payroll_runs SET base_salary = ?, vacation_overage_days = ?, deduction_amount = ?, net_amount = ? WHERE id = ?");

$changed = 0;
foreach ($rows as $r) {
    $year = (int)substr($r['salary_month'], 0, 4);
    $month = (int)substr($r['salary_month'], 5, 2);
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $monthStart = $r['salary_month'] . '-01';
    $monthEnd = $r['salary_month'] . '-' . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);
    $fullSalary = floatval($r['salary']);
    $startDate = $r['start_date'] ?: null;

    $startDay = 1;
    if ($startDate && $startDate > $monthStart) $startDay = (int)date('j', strtotime($startDate));

    $endDay = $daysInMonth;
    $termDate = $r['termination_date'] ?: null;
    if ($termDate && $termDate >= $monthStart && $termDate <= $monthEnd) {
        $endDay = (int)date('j', strtotime($termDate));
    }

    $raiseEventsStmt->execute([$r['team_member_id']]);
    $events = array_map(function($e) use ($parseNum) {
        return ['date' => $e['effective_date'], 'rate' => $parseNum($e['new_value']), 'prevRate' => $parseNum($e['previous_value'])];
    }, $raiseEventsStmt->fetchAll(PDO::FETCH_ASSOC));

    $probationSalary = floatval($r['probation_salary'] ?? 0);
    $probationMonths = intval($r['probation_months'] ?? 0);
    $probationEndDate = null;
    if ($startDate && $probationMonths > 0 && $probationSalary > 0) {
        $probationEndDate = date('Y-m-d', strtotime($startDate . " +{$probationMonths} months"));
    }

    $newBase = proratedMonthlySalary($fullSalary, $events, $year, $month, $startDay, $endDay, $daysInMonth, $probationEndDate, $probationSalary);

    $isFreelance = ($r['employment_type'] ?? '') === 'freelance' || !empty($r['attendance_policy_exempt']);
    $used = $isFreelance ? 0 : floatval($r['vacation_days_used'] ?? 0);
    $total = floatval($r['vacation_days_total'] ?? 30);
    $overage = $isFreelance ? 0 : max(0, $used - $total);
    if (strtolower((string)($r['role'] ?? '')) === 'admin') $overage = 0; // owners/admins exempt
    if (($r['overage_status'] ?? '') === 'postponed') $overage = 0; // postponed to next month
    if ($overage > 0) { // don't re-charge overage already charged in earlier approved/pending rows
        $prev = $pdo->prepare("SELECT COALESCE(SUM(vacation_overage_days),0) FROM payroll_runs WHERE team_member_id = ? AND status IN ('approved','pending') AND salary_month < ?");
        $prev->execute([$r['team_member_id'], $r['salary_month']]);
        $overage = max(0, $overage - floatval($prev->fetchColumn()));
    }
    $deduction = round($overage * ($fullSalary / $dayRate), 2);
    $newNet = max(0, $newBase - $deduction - floatval($r['termination_deduction'] ?? 0));

    $update->execute([$newBase, $overage, $deduction, $newNet, $r['id']]);
    echo "{$r['member_name']} — {$r['salary_month']}: base -> EGP {$newBase}, overage -> {$overage}d, deduction -> EGP {$deduction}, net -> EGP {$newNet}\n";
    $changed++;
}

echo json_encode(['ok' => true, 'recomputed' => $changed]) . "\n";
