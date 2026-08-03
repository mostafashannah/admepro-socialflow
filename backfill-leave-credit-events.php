<?php
// ================================================================
// One-time backfill: reconstructs leave_credit_events history for
// everything that happened BEFORE the event-logging code existed —
// approved leave/WFH requests, late-arrival Personal-Leave-then-
// vacation-spillover deductions, and unapproved-absence penalties.
// Safe to re-run: every insert is guarded by a NOT EXISTS check
// against a fixed 'reason' tag so nothing gets double-counted.
//
// Run once from the CLI on the server:
//   php backfill-leave-credit-events.php
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$settingsRow = $pdo->query("SELECT attendance_rules FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rules = $settingsRow && !empty($settingsRow['attendance_rules']) ? json_decode($settingsRow['attendance_rules'], true) : [];
$rules = is_array($rules) ? $rules : [];
$lateDeductHours = floatval($rules['lateDeductHours'] ?? 2);
$absentDeductDays = floatval($rules['absentDeductDays'] ?? 2);
$lateTriggerCount = max(1, intval($rules['lateTriggerCount'] ?? 1));

function logEvent($pdo, $teamMemberId, $memberName, $creditType, $amount, $monthKey, $workDate, $reason) {
    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM leave_credit_events WHERE team_member_id = ? AND reason = ? AND work_date <=> ? AND credit_type = ?"
    );
    $exists->execute([$teamMemberId, $reason, $workDate, $creditType]);
    if ($exists->fetchColumn() > 0) return false;
    $pdo->prepare(
        "INSERT INTO leave_credit_events (id, team_member_id, member_name, credit_type, amount, month_key, work_date, reason)
         VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$teamMemberId, $memberName, $creditType, $amount, $monthKey, $workDate, $reason]);
    return true;
}

$inserted = ['approved_requests' => 0, 'late_arrival' => 0, 'absence' => 0];

// 1. Every already-approved leave/WFH request — one event each.
$reqStmt = $pdo->query(
    "SELECT id, team_member_id, member_name, type, start_date, days FROM leave_requests WHERE status = 'approved'"
);
foreach ($reqStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $creditType = $r['type'] === 'vacation' ? 'vacation_days' : 'wfh_days';
    $days = floatval($r['days'] ?: 1);
    $monthKey = substr($r['start_date'], 0, 7);
    if (logEvent($pdo, $r['team_member_id'], $r['member_name'], $creditType, $days, $monthKey, $r['start_date'], 'backfill_leave_request_' . $r['id'])) {
        $inserted['approved_requests']++;
    }
}

// 2. Late-arrival deductions — group each member's already-flagged
// late_deducted=1 rows by month, replaying the same "Personal Leave
// first, then vacation spillover" split attendance-import.php uses,
// in chronological work_date order, resetting the 4h pool each month.
$lateStmt = $pdo->query(
    "SELECT id, team_member_id, member_name, work_date FROM attendance_records
     WHERE late_deducted = 1 AND team_member_id IS NOT NULL ORDER BY team_member_id, work_date"
);
$byMemberMonth = [];
foreach ($lateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $mk = substr($row['work_date'], 0, 7);
    $byMemberMonth[$row['team_member_id']][$mk][] = $row;
}
foreach ($byMemberMonth as $tid => $months) {
    foreach ($months as $mk => $rows) {
        $memberRow = $pdo->prepare("SELECT name, personal_leave_hours_total FROM team_members WHERE id = ?");
        $memberRow->execute([$tid]);
        $m = $memberRow->fetch(PDO::FETCH_ASSOC);
        if (!$m) continue;
        $plTotal = floatval($m['personal_leave_hours_total'] ?? 4);
        $plUsedSoFar = 0;
        $groups = intdiv(count($rows), $lateTriggerCount);
        $totalHours = $groups * $lateDeductHours;
        $fromPersonalLeave = min(max(0, $plTotal - $plUsedSoFar), $totalHours);
        $remainingHours = $totalHours - $fromPersonalLeave;
        $lastDate = $rows[count($rows) - 1]['work_date'];
        if ($fromPersonalLeave > 0) {
            if (logEvent($pdo, $tid, $m['name'], 'personal_leave_hours', $fromPersonalLeave, $mk, $lastDate, "backfill_late_arrival_pl_$mk")) {
                $inserted['late_arrival']++;
            }
        }
        if ($remainingHours > 0) {
            $deductDays = $remainingHours / 8;
            if (logEvent($pdo, $tid, $m['name'], 'vacation_days', $deductDays, $mk, $lastDate, "backfill_late_arrival_vac_$mk")) {
                $inserted['late_arrival']++;
            }
        }
    }
}

// 3. Unapproved-absence penalties already applied.
$absStmt = $pdo->query(
    "SELECT id, team_member_id, member_name, work_date FROM attendance_records
     WHERE status = 'absent' AND absence_deducted = 1 AND team_member_id IS NOT NULL"
);
foreach ($absStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $mk = substr($row['work_date'], 0, 7);
    if (logEvent($pdo, $row['team_member_id'], $row['member_name'], 'vacation_days', $absentDeductDays, $mk, $row['work_date'], 'backfill_unapproved_absence_' . $row['id'])) {
        $inserted['absence']++;
    }
}

echo json_encode(['ok' => true, 'inserted' => $inserted]) . "\n";
