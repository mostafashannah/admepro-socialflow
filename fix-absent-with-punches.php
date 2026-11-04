<?php
// Finds attendance rows marked 'absent' that nevertheless have a clock-in, and (with --apply)
// corrects them to 'present' and refunds any vacation days that were deducted for them as
// an "unapproved absence". Dry run by default — prints what it WOULD change.
//   php fix-absent-with-punches.php            (list only)
//   php fix-absent-with-punches.php --apply    (fix + refund)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
$apply = in_array('--apply', $argv, true);
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$rows = $pdo->query("SELECT id, team_member_id, member_name, work_date, check_in, check_out, absence_deducted, note
    FROM attendance_records WHERE status = 'absent' AND check_in IS NOT NULL AND check_in <> '' ORDER BY member_name, work_date")->fetchAll(PDO::FETCH_ASSOC);
echo count($rows) . " absent rows have a clock-in" . ($apply ? " — fixing:" : " (dry run):") . "\n";
$findEvt = $pdo->prepare("SELECT id, amount FROM leave_credit_events WHERE team_member_id = ? AND work_date = ? AND reason = 'unapproved_absence' AND credit_type = 'vacation_days'");
foreach ($rows as $r) {
    $refund = 0;
    if ($r['absence_deducted'] && $r['team_member_id']) {
        $findEvt->execute([$r['team_member_id'], $r['work_date']]);
        foreach ($findEvt->fetchAll(PDO::FETCH_ASSOC) as $e) $refund += (float)$e['amount'];
    }
    echo " - {$r['member_name']} {$r['work_date']} {$r['check_in']}-{$r['check_out']}" . ($refund ? " (refund {$refund} vacation day(s))" : "") . ($r['note'] ? " note: {$r['note']}" : "") . "\n";
    if (!$apply) continue;
    $pdo->prepare("UPDATE attendance_records SET status = 'present' WHERE id = ?")->execute([$r['id']]);
    if ($refund > 0) {
        $pdo->prepare("UPDATE team_members SET vacation_days_used = GREATEST(0, COALESCE(vacation_days_used,0) - ?) WHERE id = ?")->execute([$refund, $r['team_member_id']]);
        $pdo->prepare("INSERT INTO leave_credit_events (id, team_member_id, credit_type, amount, month_key, work_date, reason) VALUES (UUID(), ?, 'vacation_days', ?, ?, ?, 'absence_corrected')")
            ->execute([$r['team_member_id'], -$refund, substr($r['work_date'], 0, 7), $r['work_date']]);
    }
}
echo $apply ? "Done.\n" : "Nothing changed. Re-run with --apply to fix these rows.\n";
