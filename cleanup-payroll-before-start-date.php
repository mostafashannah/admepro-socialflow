<?php
// One-off: deletes any still-PENDING payroll_runs row generated for a
// month before the team member's real start_date — the payroll cron used
// to have no start_date field at all, so it generated a full month's pay
// for people who hadn't actually joined yet. Only touches 'pending' rows
// (never approved ones, which already became real Outstanding expenses).
// Run this AFTER setting each affected person's Start Date on their
// profile (Team > member > Edit > Start Date).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT pr.id, pr.member_name, pr.salary_month, tm.start_date
     FROM payroll_runs pr
     JOIN team_members tm ON tm.id = pr.team_member_id
     WHERE pr.status = 'pending' AND tm.start_date IS NOT NULL
       AND tm.start_date > LAST_DAY(CONCAT(pr.salary_month, '-01'))"
)->fetchAll(PDO::FETCH_ASSOC);

$del = $pdo->prepare("DELETE FROM payroll_runs WHERE id = ?");
foreach ($rows as $r) {
    echo "Removing {$r['member_name']} — {$r['salary_month']} (starts {$r['start_date']})\n";
    $del->execute([$r['id']]);
}
echo json_encode(['ok' => true, 'removed' => count($rows)]) . "\n";
