<?php
// ================================================================
// SocialFlow — monthly attendance sheet import.
//
// Accepts two formats, auto-detected from the header row:
//
// 1. Manual CSV: name, date, status[, check_in, check_out, note]
//    status is one of: present, absent, late, half_day, leave, wfh
//    (case-insensitive; anything unrecognized is stored as "present" if a
//    check-in time is given, otherwise "absent").
//
// 2. Raw biometric device export (.csv/.xls/.xlsx): Emp No., AC-No.,
//    Name, Date, Clock In 1, Clock Out 1[, Total in time] — one row per
//    day actually clocked in, nothing for days off. Since a day just
//    missing from this export could mean "absent" OR "weekend"/"holiday"
//    (the device doesn't say which), every date between the earliest and
//    latest date in the file, per employee, that ISN'T in the file gets
//    filled in as 'absent' — UNLESS it falls on a configured weekly
//    weekend day or a configured national holiday (Team → Attendance
//    Rules), in which case no row is created for it at all, so it's
//    never counted as an absence or a vacation deduction.
//
// Matches each row to a team_members row by exact name match so the
// UI can link attendance back to a profile; unmatched rows are still
// stored (team_member_id NULL) so nothing silently disappears.
// ================================================================
require_once 'config.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, apikey, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

// Same shared-key check as api.php — this endpoint writes directly to
// attendance_records with no other auth, and previously had none at all
// (any site could POST a crafted CSV and inject fake attendance data for
// any team member by name, thanks to Access-Control-Allow-Origin: *).
$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "Missing or invalid file upload (field name must be 'file')"]);
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

// ── Read the uploaded file into a plain array of rows (header + data) ──
// regardless of whether it's .csv, .xls, or .xlsx.
$origName = $_FILES['file']['name'] ?? '';
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$rows = [];
if ($ext === 'xls' || $ext === 'xlsx') {
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        http_response_code(500);
        echo json_encode(["error" => "Reading .xls/.xlsx requires phpoffice/phpspreadsheet — run: composer require phpoffice/phpspreadsheet"]);
        exit;
    }
    $originalErr = null;
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['file']['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($sheet->toArray(null, true, true, false) as $r) $rows[] = $r;
    } catch (Throwable $e) {
        $originalErr = $e->getMessage();
    }

    // Fallback 1: some biometric device software writes a RAW BIFF (old
    // binary Excel format) record stream directly, with no OLE/CFB
    // compound-file container wrapped around it — real Excel opens these
    // fine (it's lenient), but PhpSpreadsheet's strict format sniffer
    // rejects them outright with "Unable to identify a reader". Since this
    // device's export uses plain BIFF LABEL (opcode 0x0004) records for
    // every cell — including numbers and dates, stored as literal text —
    // walk the record stream directly: each LABEL record is
    // [opcode:2][reclen:2][row:2][col:2][xf:2][unused:1][strlen:1][string
    // bytes]. Reading cell text this way is exact (framed by the file's
    // own length fields), not a guess.
    if (empty($rows)) {
        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $n = strlen($raw);
        $cells = []; $maxRow = -1; $maxCol = -1; $found = 0;
        $i = 0;
        while ($i + 4 <= $n) {
            $opcode = ord($raw[$i]) | (ord($raw[$i+1]) << 8);
            $reclen = ord($raw[$i+2]) | (ord($raw[$i+3]) << 8);
            if ($opcode === 0x0004 && $i + 12 <= $n) {
                $row = ord($raw[$i+4]) | (ord($raw[$i+5]) << 8);
                $col = ord($raw[$i+6]) | (ord($raw[$i+7]) << 8);
                $strlen = ord($raw[$i+11]);
                $start = $i + 12;
                if ($start + $strlen <= $n) {
                    $cells[$row][$col] = substr($raw, $start, $strlen);
                    if ($row > $maxRow) $maxRow = $row;
                    if ($col > $maxCol) $maxCol = $col;
                    $found++;
                }
            }
            $i += 4 + ($reclen > 0 ? $reclen : 1);
        }
        if ($found >= 2 && $maxRow >= 1) {
            for ($r = 0; $r <= $maxRow; $r++) {
                $line = [];
                for ($c = 0; $c <= $maxCol; $c++) $line[] = $cells[$r][$c] ?? '';
                $rows[] = $line;
            }
        }
    }

    // Fallback 2: many biometric attendance devices export a file named
    // "X.xls" that is actually a plain HTML table, not real Excel binary.
    $htmlErr = null;
    if (empty($rows)) {
        try {
            $htmlReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Html');
            $spreadsheet = $htmlReader->load($_FILES['file']['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            foreach ($sheet->toArray(null, true, true, false) as $r) $rows[] = $r;
        } catch (Throwable $e2) {
            $htmlErr = $e2->getMessage();
        }
    }

    // Fallback 3 (last resort): some device software exports a plain
    // delimited text file (tab or comma-separated) still named "X.xls",
    // often in UTF-16 (common on Windows). Detect the encoding via BOM,
    // normalize to UTF-8, and auto-detect the delimiter from the header
    // line before giving up entirely.
    $delimErr = null;
    if (empty($rows)) {
        try {
            $raw = file_get_contents($_FILES['file']['tmp_name']);
            if (substr($raw, 0, 2) === "\xFF\xFE") { $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE'); }
            elseif (substr($raw, 0, 2) === "\xFE\xFF") { $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE'); }
            elseif (substr($raw, 0, 3) === "\xEF\xBB\xBF") { $raw = substr($raw, 3); }
            $lines = preg_split('/\r\n|\r|\n/', trim($raw));
            if (count($lines) < 2) throw new Exception('Not enough lines to be a delimited text file.');
            $headerLine = $lines[0];
            $delimiter = substr_count($headerLine, "\t") >= substr_count($headerLine, ',') ? "\t" : (substr_count($headerLine, ',') >= substr_count($headerLine, ';') ? ',' : ';');
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $rows[] = str_getcsv($line, $delimiter);
            }
            if (count($rows) < 2) throw new Exception('No data rows found after splitting.');
        } catch (Throwable $e3) {
            $delimErr = $e3->getMessage();
        }
    }

    if (empty($rows)) {
        http_response_code(400);
        echo json_encode(["error" => "Could not read spreadsheet: " . ($originalErr ?: 'unrecognized format')
            . ($htmlErr ? " | as HTML: " . $htmlErr : "")
            . ($delimErr ? " | as delimited text: " . $delimErr : "")
            . ". This file format isn't recognized — please open it in Excel and re-save as a real .xlsx or .csv file, then re-upload."]);
        exit;
    }
} else {
    $fh = fopen($_FILES['file']['tmp_name'], 'r');
    if (!$fh) { http_response_code(400); echo json_encode(["error" => "Could not read uploaded file"]); exit; }
    while (($r = fgetcsv($fh)) !== false) $rows[] = $r;
    fclose($fh);
}

if (!$rows) { http_response_code(400); echo json_encode(["error" => "Empty file"]); exit; }
$header = array_map(fn($h) => mb_strtolower(trim((string)$h)), array_shift($rows));
$colIdx = array_flip($header);

$members = $pdo->query("SELECT id, name FROM team_members")->fetchAll(PDO::FETCH_KEY_PAIR);
$byLowerName = [];
foreach ($members as $id => $name) { $byLowerName[mb_strtolower(trim($name))] = $id; }

// ── Weekend/holiday config (Team → Attendance Rules) ──
$weekendDays = [5, 6]; // PHP date('N'): 1=Mon..7=Sun — default Fri+Sat (Egypt)
$holidays = []; // set of 'Y-m-d' strings
try {
    $settingsRow = $pdo->query("SELECT attendance_rules FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $rules = $settingsRow && !empty($settingsRow['attendance_rules']) ? json_decode($settingsRow['attendance_rules'], true) : [];
    $rules = is_array($rules) ? $rules : [];
    if (!empty($rules['weekendDays']) && is_array($rules['weekendDays'])) {
        $weekendDays = array_map('intval', $rules['weekendDays']);
    }
    if (!empty($rules['holidays']) && is_array($rules['holidays'])) {
        foreach ($rules['holidays'] as $h) {
            $d = is_array($h) ? ($h['date'] ?? null) : $h;
            if ($d) $holidays[$d] = true;
        }
    }
} catch (Throwable $e) { /* fall back to defaults below */ }
$isDayOff = function (string $ymd) use ($weekendDays, $holidays) {
    if (isset($holidays[$ymd])) return true;
    $dow = (int) date('N', strtotime($ymd));
    return in_array($dow, $weekendDays, true);
};

$validStatuses = ['present','absent','late','half_day','leave','wfh'];
$upsert = $pdo->prepare(
    "INSERT INTO attendance_records (id, team_member_id, member_name, work_date, status, check_in, check_out, note)
     VALUES (UUID(), :tid, :name, :wdate, :status, :cin, :cout, :note)
     ON DUPLICATE KEY UPDATE team_member_id = VALUES(team_member_id), status = VALUES(status),
       check_in = VALUES(check_in), check_out = VALUES(check_out), note = VALUES(note)"
);

$imported = 0; $unmatched = []; $skipped = 0; $daysOffSkipped = 0;

// ── Format detection ──
$isRawDeviceFormat = isset($colIdx['name']) && isset($colIdx['date']) && !isset($colIdx['status'])
    && (isset($colIdx['clock in 1']) || isset($colIdx['clock in']));

if ($isRawDeviceFormat) {
    $cinCol = $colIdx['clock in 1'] ?? $colIdx['clock in'] ?? null;
    $coutCol = $colIdx['clock out 1'] ?? $colIdx['clock out'] ?? null;
    // Group parsed rows by employee so we can fill in the gaps per person.
    $byEmployee = []; // name => ['dates' => [ymd => [cin,cout]], 'min'=>, 'max'=>]
    foreach ($rows as $row) {
        $name = trim((string)($row[$colIdx['name']] ?? ''));
        $dateRaw = trim((string)($row[$colIdx['date']] ?? ''));
        if (!$name || !$dateRaw) { $skipped++; continue; }
        $ts = is_numeric($dateRaw) ? \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$dateRaw) : strtotime($dateRaw);
        if (!$ts) { $skipped++; continue; }
        $ymd = date('Y-m-d', $ts);
        if (!isset($byEmployee[$name])) $byEmployee[$name] = ['dates' => [], 'min' => $ymd, 'max' => $ymd];
        $byEmployee[$name]['dates'][$ymd] = [
            $cinCol !== null ? trim((string)($row[$cinCol] ?? '')) : null,
            $coutCol !== null ? trim((string)($row[$coutCol] ?? '')) : null,
        ];
        if ($ymd < $byEmployee[$name]['min']) $byEmployee[$name]['min'] = $ymd;
        if ($ymd > $byEmployee[$name]['max']) $byEmployee[$name]['max'] = $ymd;
    }

    foreach ($byEmployee as $name => $info) {
        $teamMemberId = $byLowerName[mb_strtolower($name)] ?? null;
        if (!$teamMemberId) $unmatched[$name] = true;

        // Present/late rows for every date actually in the file.
        foreach ($info['dates'] as $ymd => [$cin, $cout]) {
            $upsert->execute([
                ':tid' => $teamMemberId, ':name' => $name, ':wdate' => $ymd,
                ':status' => 'present', ':cin' => $cin ?: null, ':cout' => $cout ?: null, ':note' => null,
            ]);
            $imported++;
        }

        // Fill every gap in [min, max] with 'absent' — except weekends/holidays,
        // which get no row at all (never counted as absence or vacation).
        $cursor = new DateTime($info['min']);
        $end = new DateTime($info['max']);
        while ($cursor <= $end) {
            $ymd = $cursor->format('Y-m-d');
            if (!isset($info['dates'][$ymd])) {
                if ($isDayOff($ymd)) {
                    $daysOffSkipped++;
                } else {
                    $upsert->execute([
                        ':tid' => $teamMemberId, ':name' => $name, ':wdate' => $ymd,
                        ':status' => 'absent', ':cin' => null, ':cout' => null, ':note' => null,
                    ]);
                    $imported++;
                }
            }
            $cursor->modify('+1 day');
        }
    }
} else {
    foreach (['name','date','status'] as $required) {
        if (!isset($colIdx[$required])) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required column: {$required}. Expected columns: name, date, status[, check_in, check_out, note] — or a raw device export with Name, Date, Clock In 1[, Clock Out 1]."]);
            exit;
        }
    }
    foreach ($rows as $row) {
        $name = trim((string)($row[$colIdx['name']] ?? ''));
        $dateRaw = trim((string)($row[$colIdx['date']] ?? ''));
        $statusRaw = mb_strtolower(trim((string)($row[$colIdx['status']] ?? '')));
        if (!$name || !$dateRaw) { $skipped++; continue; }

        $ts = is_numeric($dateRaw) && class_exists('PhpOffice\\PhpSpreadsheet\\Shared\\Date')
            ? \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$dateRaw) : strtotime($dateRaw);
        if (!$ts) { $skipped++; continue; }
        $workDate = date('Y-m-d', $ts);

        // A weekend/holiday should never count as a day off, even if the
        // sheet explicitly marked it 'absent' by mistake — skip the row
        // entirely rather than storing a status that would get deducted.
        if ($isDayOff($workDate) && in_array($statusRaw, ['absent', ''], true)) {
            $daysOffSkipped++;
            continue;
        }

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
}

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
    // request covering that date deducts a fixed number of days. Weekend/
    // holiday dates never reach here as 'absent' rows in the first place
    // (skipped above at import time), so nothing further to exclude.
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
    'days_off_skipped' => $daysOffSkipped,
    'format' => $isRawDeviceFormat ? 'raw_device_export' : 'manual_csv',
    'unmatched_names' => array_keys($unmatched),
    'rules_applied' => $rulesDeducted,
]);
