<?php
// ================================================================
// Runs once daily (server crontab — e.g. `10 0 * * * php
// /var/www/socialflow/terminate-members-cron.php`).
//
// Flips a team member to status='inactive' the day AFTER their
// termination_date (last working day) — not on the last day itself, since
// they're still meant to have real system access and appear on the
// Timeline through the end of that day. Once inactive: login is blocked
// (see handleLogin in app.jsx), and they're excluded from the Timeline
// and the active "Human Team" list (see UsersPage/MyTimelinePage).
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$members = $pdo->query(
    "SELECT id, name FROM team_members WHERE termination_date IS NOT NULL AND termination_date < CURDATE() AND status != 'inactive'"
)->fetchAll(PDO::FETCH_ASSOC);

$update = $pdo->prepare("UPDATE team_members SET status = 'inactive' WHERE id = :id");
$log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'Team member deactivated', 'team', :details, 'success', 'cron')");

$count = 0;
foreach ($members as $m) {
    $update->execute([':id' => $m['id']]);
    $log->execute([':details' => "{$m['name']} switched to inactive — past their termination date."]);
    $count++;
}

echo "Deactivated {$count} team member(s) past their termination date.\n";
