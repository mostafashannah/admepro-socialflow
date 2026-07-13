<?php
// ================================================================
// SocialFlow — monthly attendance sheet import.
// Accepts a CSV upload (multipart/form-data, field name "file") with
// columns: name, date, status[, check_in, check_out, note]
// status is one of: present, absent, late, half_day, leave, wfh
// (case-insensitive; anything unrecognized is stored as "present" if a
// check-in time is given, otherwise "absent").
// Matches each row to a team_members row by exact name match so the
// UI can link attendance back to a profile; unmatched rows are still
// stored (team_member_id NULL) so nothing silently disappears.
// ================================================================
require_once 'config.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "Missing or invalid file upload (field name must be 'file')"]);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$members = $pdo->query("SELECT id, name FROM team_members")->fetchAll(PDO::FETCH_KEY_PAIR);
// Case-insensitive name -> id map
$byLowerName = [];
foreach ($members as $id => $name) { $byLowerName[mb_strtolower(trim($name))] = $id; }

$validStatuses = ['present','absent','late','half_day','leave','wfh'];

$fh = fopen($_FILES['file']['tmp_name'], 'r');
if (!$fh) { http_response_code(400); echo json_encode(["error" => "Could not read uploaded file"]); exit; }

$header = fgetcsv($fh);
if (!$header) { http_response_code(400); echo json_encode(["error" => "Empty file"]); exit; }
$header = array_map(fn($h) => mb_strtolower(trim($h)), $header);
$colIdx = array_flip($header);

foreach (['name','date','status'] as $required) {
    if (!isset($colIdx[$required])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required column: {$required}. Expected columns: name, date, status[, check_in, check_out, note]"]);
        exit;
    }
}

$upsert = $pdo->prepare(
    "INSERT INTO attendance_records (id, team_member_id, member_name, work_date, status, check_in, check_out, note)
     VALUES (UUID(), :tid, :name, :wdate, :status, :cin, :cout, :note)
     ON DUPLICATE KEY UPDATE team_member_id = VALUES(team_member_id), status = VALUES(status),
       check_in = VALUES(check_in), check_out = VALUES(check_out), note = VALUES(note)"
);

$imported = 0; $unmatched = []; $skipped = 0;

while (($row = fgetcsv($fh)) !== false) {
    $name = trim($row[$colIdx['name']] ?? '');
    $dateRaw = trim($row[$colIdx['date']] ?? '');
    $statusRaw = mb_strtolower(trim($row[$colIdx['status']] ?? ''));
    if (!$name || !$dateRaw) { $skipped++; continue; }

    $ts = strtotime($dateRaw);
    if (!$ts) { $skipped++; continue; }
    $workDate = date('Y-m-d', $ts);

    $status = in_array($statusRaw, $validStatuses, true) ? $statusRaw : (!empty($row[$colIdx['check_in'] ?? -1]) ? 'present' : 'absent');
    $teamMemberId = $byLowerName[mb_strtolower($name)] ?? null;
    if (!$teamMemberId) $unmatched[$name] = true;

    $upsert->execute([
        ':tid' => $teamMemberId,
        ':name' => $name,
        ':wdate' => $workDate,
        ':status' => $status,
        ':cin' => !empty($row[$colIdx['check_in'] ?? -1]) ? $row[$colIdx['check_in']] : null,
        ':cout' => !empty($row[$colIdx['check_out'] ?? -1]) ? $row[$colIdx['check_out']] : null,
        ':note' => !empty($row[$colIdx['note'] ?? -1]) ? $row[$colIdx['note']] : null,
    ]);
    $imported++;
}
fclose($fh);

// ── Attendance rules: late-arrival + unapproved-absence deductions ──
// Configured from the app (Users > Attendance tab, admin only), stored as
// JSON on app_settings.attendance_rules. Applied on every import so it
// covers both freshly-imported rows and any older undeducted rows.
$rulesDeducted = ['late' => 0, 'absent' => 0];
try {
    $settingsRow = $pdo->query("SELECT attendance_rules FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $rules = $settingsRow && !empty($settingsRow['attendance_rules']) ? json_decode($settingsRow['attendance_rules'], true) : [];
    $rules = is_array($rules) ? $rules : [];

    // Late arrivals: every N late check-ins (after the configured threshold
    // time) deducts a fixed number of hours (converted to a fraction of a
    // vacation day) from that member's vacation_days_used.
    if (!empty($rules['lateEnabled']) && floatval($rules['lateDeductHours'] ?? 0) > 0) {
        $threshold = $rules['lateThresholdTime'] ?? '09:15';
        $triggerCount = max(1, intval($rules['lateTriggerCount'] ?? 1));
        $deductHours = floatval($rules['lateDeductHours']);

        $lateStmt = $pdo->prepare(
            "SELECT id, team_member_id FROM attendance_records
             WHERE team_member_id IS NOT NULL AND late_deducted = 0
               AND check_in IS NOT NULL AND check_in > :thresh
               AND status NOT IN ('leave','wfh')
             ORDER BY team_member_id, work_date"
        );
        $lateStmt->execute([':thresh' => $threshold]);
        $byMember = [];
        foreach ($lateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byMember[$row['team_member_id']][] = $row['id'];
        }
        foreach ($byMember as $tid => $ids) {
            $groups = intdiv(count($ids), $triggerCount);
            if ($groups <= 0) continue;
            $toMark = array_slice($ids, 0, $groups * $triggerCount);
            $placeholders = implode(',', array_fill(0, count($toMark), '?'));
            $pdo->prepare("UPDATE attendance_records SET late_deducted = 1 WHERE id IN ($placeholders)")->execute($toMark);
            $deductDays = ($groups * $deductHours) / 8;
            $pdo->prepare("UPDATE team_members SET vacation_days_used = COALESCE(vacation_days_used,0) + ? WHERE id = ?")->execute([$deductDays, $tid]);
            $rulesDeducted['late'] += $groups;
        }
    }

    // Unapproved absences: any 'absent' row with no approved vacation/WFH
    // request covering that date deducts a fixed number of days.
    if (!empty($rules['absentEnabled']) && floatval($rules['absentDeductDays'] ?? 0) > 0) {
        $deductDays = floatval($rules['absentDeductDays']);
        $absentStmt = $pdo->query(
            "SELECT id, team_member_id, work_date FROM attendance_records
             WHERE status = 'absent' AND absence_deducted = 0 AND team_member_id IS NOT NULL"
        );
        $checkStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM leave_requests
             WHERE team_member_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?"
        );
        foreach ($absentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $checkStmt->execute([$row['team_member_id'], $row['work_date'], $row['work_date']]);
            $covered = $checkStmt->fetchColumn() > 0;
            if (!$covered) {
                $pdo->prepare("UPDATE team_members SET vacation_days_used = COALESCE(vacation_days_used,0) + ? WHERE id = ?")->execute([$deductDays, $row['team_member_id']]);
                $rulesDeducted['absent']++;
            }
            $pdo->prepare("UPDATE attendance_records SET absence_deducted = 1 WHERE id = ?")->execute([$row['id']]);
        }
    }
} catch (Exception $e) { /* rules are best-effort; import itself already succeeded */ }

echo json_encode([
    'ok' => true,
    'imported' => $imported,
    'skipped' => $skipped,
    'unmatched_names' => array_keys($unmatched),
    'rules_applied' => $rulesDeducted,
]);
