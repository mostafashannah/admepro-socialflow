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
    "SELECT id, name, salary, vacation_days_used, vacation_days_total FROM team_members WHERE status != 'inactive' AND salary IS NOT NULL AND salary > 0"
)->fetchAll(PDO::FETCH_ASSOC);

$exists = $pdo->prepare("SELECT 1 FROM payroll_runs WHERE team_member_id = ? AND salary_month = ? LIMIT 1");
$insert = $pdo->prepare(
    "INSERT INTO payroll_runs (id, team_member_id, member_name, salary_month, base_salary, vacation_overage_days, deduction_amount, net_amount, status)
     VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, 'pending')"
);

$created = 0;
foreach ($members as $m) {
    $exists->execute([$m['id'], $lastMonth]);
    if ($exists->fetchColumn()) continue; // already generated this month — safe to re-run

    $baseSalary = floatval($m['salary']);
    $used = floatval($m['vacation_days_used'] ?? 0);
    $total = floatval($m['vacation_days_total'] ?? 21);
    $overage = max(0, $used - $total);
    $deduction = round($overage * ($baseSalary / $dayRate), 2);
    $net = max(0, $baseSalary - $deduction);

    $insert->execute([$m['id'], $m['name'], $lastMonth, $baseSalary, $overage, $deduction, $net]);
    $created++;
}

echo json_encode(['ok' => true, 'month' => $lastMonth, 'created' => $created]) . "\n";
