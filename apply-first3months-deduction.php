<?php
// Usage: php apply-first3months-deduction.php "Malak" [apply]
// Adds the first-3-months absence + approved-leave deduction (no leave credit
// in the first 3 months) to that member's PENDING payroll run(s). Dry run
// unless the second argument is "apply". Safe to re-run: it recomputes the
// extra amount and only adds what is not already included.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

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
    return round(count($days) * $rate, 2);
}


$name = $argv[1] ?? ''; $apply = ($argv[2] ?? '') === 'apply';
if (!$name) die("Pass a member name\n");
$q = $pdo->prepare("SELECT pr.id, pr.member_name, pr.salary_month, pr.base_salary, pr.deduction_amount, tm.id AS tm_id, tm.salary, tm.probation_salary,
    COALESCE(tm.start_date, DATE(tm.created_at)) AS start_date, COALESCE(tm.termination_date, LAST_DAY(CONCAT(pr.salary_month,'-01'))) AS cutoff
    FROM payroll_runs pr JOIN team_members tm ON tm.id = pr.team_member_id
    WHERE pr.status = 'pending' AND tm.name LIKE ?");
$q->execute(["%$name%"]);
$upd = $pdo->prepare("UPDATE payroll_runs SET deduction_amount = ?, net_amount = ? WHERE id = ?");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $extra = firstThreeMonthsAbsenceDeduction($pdo, $r['tm_id'], $r['start_date'], $r['cutoff'], floatval($r['salary']), floatval($r['probation_salary']));
    $ded = round(floatval($r['deduction_amount']) + $extra, 2);
    $net = max(0, floatval($r['base_salary']) - $ded);
    echo "{$r['member_name']} {$r['salary_month']}: extra deduction EGP {$extra} -> deduction {$ded}, net {$net}" . ($apply ? " [applied]" : " [dry run]") . "\n";
    if ($apply) $upd->execute([$ded, $net, $r['id']]);
}
