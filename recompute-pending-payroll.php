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
    "SELECT pr.id, pr.team_member_id, pr.member_name, pr.salary_month,
            tm.salary, tm.probation_salary, tm.probation_months, tm.termination_date, tm.employment_type,
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

    $isFreelance = ($r['employment_type'] ?? '') === 'freelance';
    $used = $isFreelance ? 0 : floatval($r['vacation_days_used'] ?? 0);
    $total = floatval($r['vacation_days_total'] ?? 30);
    $overage = $isFreelance ? 0 : max(0, $used - $total);
    $deduction = round($overage * ($fullSalary / $dayRate), 2);
    $newNet = max(0, $newBase - $deduction);

    $update->execute([$newBase, $overage, $deduction, $newNet, $r['id']]);
    echo "{$r['member_name']} — {$r['salary_month']}: base -> EGP {$newBase}, overage -> {$overage}d, deduction -> EGP {$deduction}, net -> EGP {$newNet}\n";
    $changed++;
}

echo json_encode(['ok' => true, 'recomputed' => $changed]) . "\n";
