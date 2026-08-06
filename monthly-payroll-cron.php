<?php
// ================================================================
// Runs once, scheduled for the 5th of every month (server crontab —
// see crontab -e: `5 6 5 * * php /var/www/socialflow/monthly-payroll-cron.php`).
//
// For each active team member, generates a PENDING payroll_runs row for
// last month — base salary, minus a deduction for any vacation days used
// beyond their yearly credit — for an admin to review and approve on the
// Finance > Payroll page. Nothing is added to Outstanding automatically;
// approval is a manual step (deciding whether to actually make it a
// payable liability is a real financial decision, not something to
// silently automate).
//
// Only runs for a month once last month's attendance has actually been
// uploaded (checked via attendance_records having rows in that month) —
// otherwise the vacation/deduction numbers would be incomplete, so it
// just does nothing and can be safely re-triggered (e.g. by a retry cron)
// until the sheet is in.
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$lastMonth = date('Y-m', strtotime('first day of last month'));

$hasAttendance = $pdo->prepare(
    "SELECT COUNT(*) FROM attendance_records WHERE work_date LIKE ?"
);
$hasAttendance->execute([$lastMonth . '-%']);
if ($hasAttendance->fetchColumn() == 0) {
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => "no attendance uploaded yet for $lastMonth"]) . "\n";
    exit;
}

$dayRate = 30; // salary / 30 as the per-day deduction rate

$members = $pdo->query(
    "SELECT id, name, salary, vacation_days_used, vacation_days_total, start_date FROM team_members WHERE status != 'inactive' AND salary IS NOT NULL AND salary > 0"
)->fetchAll(PDO::FETCH_ASSOC);

$exists = $pdo->prepare("SELECT 1 FROM payroll_runs WHERE team_member_id = ? AND salary_month = ? LIMIT 1");
$insert = $pdo->prepare(
    "INSERT INTO payroll_runs (id, team_member_id, member_name, salary_month, base_salary, vacation_overage_days, deduction_amount, net_amount, status)
     VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, 'pending')"
);
$raiseEventsStmt = $pdo->prepare(
    "SELECT effective_date, previous_value, new_value FROM team_member_events WHERE team_member_id = ? AND event_type = 'salary_raise' AND effective_date IS NOT NULL ORDER BY effective_date ASC"
);

$year = (int)substr($lastMonth, 0, 4);
$month = (int)substr($lastMonth, 5, 2);
$monthStart = $lastMonth . '-01';
$daysInMonth = (int)date('t', strtotime($monthStart));
$monthEnd = $lastMonth . '-' . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);

// Mirrors app.jsx's computeProratedMonthlySalary exactly: a salary raise
// (or a start_date) landing partway through the month means part of it
// was actually earned at the old rate and part at the new one, instead
// of the whole month being paid at whatever the salary is right now.
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

$created = 0;
$skipped = 0;
foreach ($members as $m) {
    $exists->execute([$m['id'], $lastMonth]);
    if ($exists->fetchColumn()) continue; // already generated this month — safe to re-run

    $fullSalary = floatval($m['salary']);
    $startDate = $m['start_date'] ?: null;

    // Not employed yet during this payroll month at all — skip entirely.
    if ($startDate && $startDate > $monthEnd) { $skipped++; continue; }

    // Joined partway through this month — only count days from their real
    // start date onward, instead of assuming a full month.
    $startDay = 1;
    if ($startDate && $startDate > $monthStart) {
        $startDay = (int)date('j', strtotime($startDate));
    }

    $raiseEventsStmt->execute([$m['id']]);
    $events = array_map(function($r) use ($parseNum) {
        return ['date' => $r['effective_date'], 'rate' => $parseNum($r['new_value']), 'prevRate' => $parseNum($r['previous_value'])];
    }, $raiseEventsStmt->fetchAll(PDO::FETCH_ASSOC));

    $baseSalary = proratedMonthlySalary($fullSalary, $events, $year, $month, $startDay, $daysInMonth);

    $used = floatval($m['vacation_days_used'] ?? 0);
    $total = floatval($m['vacation_days_total'] ?? 30);
    $overage = max(0, $used - $total);
    $deduction = round($overage * ($fullSalary / $dayRate), 2);
    $net = max(0, $baseSalary - $deduction);

    $insert->execute([$m['id'], $m['name'], $lastMonth, $baseSalary, $overage, $deduction, $net]);
    $created++;
}

echo json_encode(['ok' => true, 'month' => $lastMonth, 'created' => $created, 'skipped_not_yet_hired' => $skipped]) . "\n";
