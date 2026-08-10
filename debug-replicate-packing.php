<?php
// READ-ONLY diagnostic — replicates generateDailySchedule's packing logic
// in PHP using the EXACT order posts come back in from the same query the
// app uses on load (qe("Post",{},"-created_at")), to see whether the
// overflow cutoff should really be after item 4 (Aug Plan + 3) or item 6
// (Aug Plan + 5) — and compare against what the app actually decided.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tm = $pdo->query("SELECT id, email FROM team_members WHERE name LIKE '%Sherif%'")->fetch(PDO::FETCH_ASSOC);
$email = $tm['email'];

// Same ordering the app's initial load uses.
$stmt = $pdo->prepare(
    "SELECT id, title, due_date, due_time, estimated_minutes, created_at
     FROM posts
     WHERE (assigned_to = :e OR content_assigned_to = :e OR design_assigned_to = :e)
       AND stage = 'design'
     ORDER BY created_at DESC"
);
$stmt->execute([':e' => $email]);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// The previous (buggy) run already shifted 6 of these to 2026-08-10 in the
// real DB — restore that for THIS simulation only (not a real DB write) so
// we're replicating the original, pre-shift scenario.
foreach ($all as &$p) {
    if (strpos($p['title'], 'Aug Calendar') !== false) $p['due_date'] = '2026-08-09';
}
unset($p);

$today = '2026-08-09';
$WORKING_START = 10 * 60;
$WORKING_END = 19 * 60;

// Replicate the myPosts filter: due_date === today, OR (overdue AND still
// in 'design' stage), OR no due_date at all (only for today).
$myPosts = array_values(array_filter($all, function($p) use ($today) {
    if (!empty($p['due_date'])) {
        return $p['due_date'] === $today || $p['due_date'] < $today;
    }
    return true;
}));

echo "=== Raw DB order (created_at DESC) of candidate posts ===\n";
foreach ($myPosts as $p) echo "{$p['title']} | due_date={$p['due_date']} due_time={$p['due_time']} est={$p['estimated_minutes']} created_at={$p['created_at']}\n";

// Replicate sort: overdue first, then priorityScore (assume all equal —
// stable sort keeps relative order for ties).
usort($myPosts, function($a, $b) use ($today) {
    $aOverdue = !empty($a['due_date']) && $a['due_date'] < $today ? 1 : 0;
    $bOverdue = !empty($b['due_date']) && $b['due_date'] < $today ? 1 : 0;
    return $bOverdue - $aOverdue; // stable in PHP 8+
});

echo "\n=== Packing order after overdue-first sort ===\n";
$cursor_tracker = [];
$slotEnds = [];
$totalCursorBase = $WORKING_START;
foreach ($myPosts as $p) {
    $isOverdue = !empty($p['due_date']) && $p['due_date'] < $today;
    $est = $p['estimated_minutes'] ? (int)$p['estimated_minutes'] : 60;
    if (!empty($p['due_time']) && !$isOverdue) {
        [$hh, $mm] = array_map('intval', explode(':', $p['due_time']));
        $startMins = $hh * 60 + $mm;
        $cursor = max($WORKING_START, min($startMins, $WORKING_END - $est));
    } else {
        $cursor = $WORKING_START;
        $ends = $slotEnds; sort($ends);
        foreach ($ends as $end) { if ($end >= $cursor) $cursor = $end; }
    }
    $end = $cursor + $est;
    $slotEnds[] = $end;
    $overflow = $cursor >= $WORKING_END;
    $fmt = fn($m) => sprintf('%02d:%02d', intdiv($m,60), $m%60);
    echo "{$p['title']} | overdue=" . ($isOverdue?'Y':'N') . " start=" . $fmt($cursor) . " end=" . $fmt($end) . " " . ($overflow ? "OVERFLOW" : "fits") . "\n";
}
