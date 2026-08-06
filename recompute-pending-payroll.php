<?php
// One-off: recalculates base_salary/net_amount on already-generated
// PENDING payroll_runs rows using the same day-by-day proration the cron
// now uses (mid-month salary raises + start_date), since rows generated
// before that fix just used whatever the salary is today for the whole
// month. Never touches approved/rejected rows.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dayRate = 30;
$parseNum = function($v) { return (float)preg_replace('/[^0-9.]/', '', (string)$v); };
function proratedMonthlySalary($currentSalary, $events, $year, $month, $startDay, $daysInMonth) {
    $total = 0.0;
    for ($d = $startDay; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $rate = $currentSalary;
        $applicable = null;
        foreach ($events as $e) { if ($e['date'] <= $dateStr) $applicable = $e; }
        if ($applicable) $rate = $applicable['rate'];
        elseif (count($events) > 0 && $events[0]['prevRate'] > 0) $rate = $events[0]['prevRate'];
        $total += $rate / $daysInMonth;
    }
    return round($total, 2);
}

$rows = $pdo->query(
    "SELECT pr.id, pr.team_member_id, pr.member_name, pr.salary_month, pr.vacation_overage_days,
            tm.salary, tm.start_date
     FROM payroll_runs pr JOIN team_members tm ON tm.id = pr.team_member_id
     WHERE pr.status = 'pending'"
)->fetchAll(PDO::FETCH_ASSOC);

$raiseEventsStmt = $pdo->prepare(
    "SELECT effective_date, previous_value, new_value FROM team_member_events WHERE team_member_id = ? AND event_type = 'salary_raise' AND effective_date IS NOT NULL ORDER BY effective_date ASC"
);
$update = $pdo->prepare("UPDATE payroll_runs SET base_salary = ?, deduction_amount = ?, net_amount = ? WHERE id = ?");

$changed = 0;
foreach ($rows as $r) {
    $year = (int)substr($r['salary_month'], 0, 4);
    $month = (int)substr($r['salary_month'], 5, 2);
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $monthStart = $r['salary_month'] . '-01';
    $fullSalary = floatval($r['salary']);
    $startDate = $r['start_date'] ?: null;

    $startDay = 1;
    if ($startDate && $startDate > $monthStart) $startDay = (int)date('j', strtotime($startDate));

    $raiseEventsStmt->execute([$r['team_member_id']]);
    $events = array_map(function($e) use ($parseNum) {
        return ['date' => $e['effective_date'], 'rate' => $parseNum($e['new_value']), 'prevRate' => $parseNum($e['previous_value'])];
    }, $raiseEventsStmt->fetchAll(PDO::FETCH_ASSOC));

    $newBase = proratedMonthlySalary($fullSalary, $events, $year, $month, $startDay, $daysInMonth);
    $overage = floatval($r['vacation_overage_days']);
    $deduction = round($overage * ($fullSalary / $dayRate), 2);
    $newNet = max(0, $newBase - $deduction);

    $update->execute([$newBase, $deduction, $newNet, $r['id']]);
    echo "{$r['member_name']} — {$r['salary_month']}: base -> EGP {$newBase}, net -> EGP {$newNet}\n";
    $changed++;
}

echo json_encode(['ok' => true, 'recomputed' => $changed]) . "\n";
