<?php
// One-time cleanup for attendance_records rows that ended up duplicated
// under the SAME team_member_id + work_date — caused by the old remap
// logic in attendance-import.php (fixed alongside this script) blindly
// setting team_member_id on a raw device-label row ("EYAD", "SHMS") without
// checking whether that person already had a row for the same day under
// their real matched name. For each such pair, keeps the row whose
// member_name looks like a real full name (has a space, i.e. "Eyad
// Abdelalem" over "EYAD") — or the first one if neither looks more
// "real" — folds in any check_in/check_out/note the other row had, and
// deletes the leftover duplicate. Only touches groups where
// team_member_id IS NOT NULL; unmatched raw-label duplicates (all still
// team_member_id IS NULL, e.g. "MEEN"/"74"/"79") need a human to map them
// to the right person first via the Attendance import "remap" UI — this
// script leaves those alone on purpose.
require_once __DIR__ . '/config.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$groups = $pdo->query("
    SELECT team_member_id, work_date, COUNT(*) c
    FROM attendance_records
    WHERE team_member_id IS NOT NULL
    GROUP BY team_member_id, work_date
    HAVING c > 1
")->fetchAll(PDO::FETCH_ASSOC);

$merged = 0;
foreach ($groups as $g) {
    $rows = $pdo->prepare("SELECT id, member_name, check_in, check_out, note FROM attendance_records WHERE team_member_id = ? AND work_date = ? ORDER BY (member_name LIKE '% %') DESC, id ASC");
    $rows->execute([$g['team_member_id'], $g['work_date']]);
    $rowSet = $rows->fetchAll(PDO::FETCH_ASSOC);
    if (count($rowSet) < 2) continue;
    $keep = array_shift($rowSet); // the "% %" ORDER BY puts a real full name first
    foreach ($rowSet as $dupe) {
        $pdo->prepare("UPDATE attendance_records SET check_in = COALESCE(check_in, ?), check_out = COALESCE(check_out, ?), note = COALESCE(NULLIF(note,''), ?) WHERE id = ?")
            ->execute([$dupe['check_in'], $dupe['check_out'], $dupe['note'], $keep['id']]);
        $pdo->prepare("DELETE FROM attendance_records WHERE id = ?")->execute([$dupe['id']]);
        $merged++;
        echo "Merged \"{$dupe['member_name']}\" into \"{$keep['member_name']}\" for {$g['work_date']}\n";
    }
}
echo "Done. Merged {$merged} duplicate row(s).\n";
