<?php
// One-off cleanup: "vacation days must count from the joined date" — before
// the start/termination-date guards existed in attendance-import.php, the
// company-wide reconciliation pass created real 'absent' rows for EVERY
// active member across the whole imported date range, with no regard for
// when that specific person actually joined (or, for a terminated member,
// their last real day). Those bogus absent rows then fed the unapproved-
// absence rule, inflating vacation_days_used with days that never
// happened — e.g. Mostafa Elnady showing "-50d over vacation credit" for
// a genuinely normal attendance record.
//
// For every team member with a resolvable start date:
//   1. Delete any attendance_records dated before their start date, or
//      after their termination date (if terminated) — they never worked
//      those days.
//   2. Recompute vacation_days_used from leave_credit_events (the actual
//      audit trail of every increment) restricted to that same valid
//      window, instead of trusting whatever's currently on the row.
// Then recompute-pending-payroll.php should be re-run to refresh any
// already-generated pending payroll rows against the corrected numbers.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$members = $pdo->query("SELECT id, name, COALESCE(start_date, DATE(created_at)) AS start_date, termination_date FROM team_members")->fetchAll(PDO::FETCH_ASSOC);

$delAttStmt = $pdo->prepare("DELETE FROM attendance_records WHERE team_member_id = ? AND work_date < ?");
$delAttEndStmt = $pdo->prepare("DELETE FROM attendance_records WHERE team_member_id = ? AND work_date > ?");
$delEventsStmt = $pdo->prepare("DELETE FROM leave_credit_events WHERE team_member_id = ? AND credit_type = 'vacation_days' AND work_date IS NOT NULL AND work_date < ?");
$delEventsEndStmt = $pdo->prepare("DELETE FROM leave_credit_events WHERE team_member_id = ? AND credit_type = 'vacation_days' AND work_date IS NOT NULL AND work_date > ?");
$sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM leave_credit_events WHERE team_member_id = ? AND credit_type = 'vacation_days'");
$updateStmt = $pdo->prepare("UPDATE team_members SET vacation_days_used = ? WHERE id = ?");

$changed = 0;
foreach ($members as $m) {
    $startDate = $m['start_date'] ?: null;
    $termDate = $m['termination_date'] ?: null;
    $attDeleted = 0; $eventsDeleted = 0;

    if ($startDate) {
        $delAttStmt->execute([$m['id'], $startDate]);
        $attDeleted += $delAttStmt->rowCount();
        $delEventsStmt->execute([$m['id'], $startDate]);
        $eventsDeleted += $delEventsStmt->rowCount();
    }
    if ($termDate) {
        $delAttEndStmt->execute([$m['id'], $termDate]);
        $attDeleted += $delAttEndStmt->rowCount();
        $delEventsEndStmt->execute([$m['id'], $termDate]);
        $eventsDeleted += $delEventsEndStmt->rowCount();
    }

    $sumStmt->execute([$m['id']]);
    $correctUsed = round((float)$sumStmt->fetchColumn(), 2);
    $updateStmt->execute([$correctUsed, $m['id']]);

    if ($attDeleted > 0 || $eventsDeleted > 0) {
        echo "{$m['name']}: deleted {$attDeleted} attendance row(s), {$eventsDeleted} vacation-credit event(s) outside [{$startDate} .. " . ($termDate ?: 'ongoing') . "] — vacation_days_used now {$correctUsed}\n";
        $changed++;
    }
}

echo json_encode(['ok' => true, 'members_corrected' => $changed]) . "\n";
