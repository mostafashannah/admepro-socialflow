<?php
// ================================================================
// ONE-OFF backfill — run once, then delete this file.
//
// Retroactively applies the "WFH over the monthly quota counts as
// half-day" rule (see decideLeaveRequest in app.jsx) to APPROVED wfh
// leave_requests that were approved before that rule existed and so
// over-drew wfh_days_used past wfh_days_total (e.g. an 8-day WFH request
// on a 2-day/month quota leaving wfh_days_used=8).
//
// For each such request: caps the credited days at wfh_days_total,
// reduces the member's wfh_days_used by the overflow, logs a correction
// leave_credit_event pair (negative wfh_days / positive wfh_half_day) so
// the history stays auditable, and appends a note to the request's
// decision_note. Safe to re-run — a request already carrying the
// "[retroactively split]" marker in its decision_note is skipped.
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$requests = $pdo->query(
    "SELECT lr.id, lr.team_member_id, lr.member_name, lr.days, lr.start_date, lr.decision_note,
            tm.wfh_days_total, tm.wfh_days_used
     FROM leave_requests lr
     JOIN team_members tm ON tm.id = lr.team_member_id
     WHERE lr.type = 'wfh' AND lr.status = 'approved'
       AND lr.days > COALESCE(tm.wfh_days_total, 2)
       AND (lr.decision_note IS NULL OR lr.decision_note NOT LIKE '%[retroactively split]%')"
)->fetchAll(PDO::FETCH_ASSOC);

$fixed = [];
foreach ($requests as $r) {
    $total = floatval($r['wfh_days_total'] ?? 2);
    $overflow = floatval($r['days']) - $total;
    if ($overflow <= 0) continue;

    $newUsed = max(0, floatval($r['wfh_days_used']) - $overflow);
    $pdo->prepare("UPDATE team_members SET wfh_days_used = ? WHERE id = ?")->execute([$newUsed, $r['team_member_id']]);

    $monthKey = substr($r['start_date'], 0, 7);
    $pdo->prepare(
        "INSERT INTO leave_credit_events (id, team_member_id, credit_type, amount, month_key, work_date, reason)
         VALUES (UUID(), ?, 'wfh_days', ?, ?, ?, 'retroactive_split_correction')"
    )->execute([$r['team_member_id'], -$overflow, $monthKey, $r['start_date']]);
    $pdo->prepare(
        "INSERT INTO leave_credit_events (id, team_member_id, credit_type, amount, month_key, work_date, reason)
         VALUES (UUID(), ?, 'wfh_half_day', ?, ?, ?, 'retroactive_split_correction')"
    )->execute([$r['team_member_id'], $overflow, $monthKey, $r['start_date']]);

    $note = trim(($r['decision_note'] ? $r['decision_note'] . ' — ' : '') . "{$overflow} of {$r['days']} day(s) over the monthly WFH quota — counted as half-day. [retroactively split]");
    $pdo->prepare("UPDATE leave_requests SET decision_note = ? WHERE id = ?")->execute([$note, $r['id']]);

    $fixed[] = ['member' => $r['member_name'], 'request_id' => $r['id'], 'was_used' => $r['wfh_days_used'], 'now_used' => $newUsed, 'overflow_days' => $overflow];
}

echo json_encode(['ok' => true, 'fixed_count' => count($fixed), 'fixed' => $fixed], JSON_PRETTY_PRINT) . "\n";
