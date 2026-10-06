<?php
// ================================================================
// Runs once daily (server crontab — e.g. `5 0 * * * php
// /var/www/socialflow/activate-members-cron.php`).
//
// Team members created before their start date are saved as
// status='inactive' + pending_start=1 (login blocked, off the Timeline).
// On their start date this flips them to active. Only rows flagged
// pending_start are touched, so someone made inactive for any other
// reason (e.g. terminated) is never reactivated by this.
// ================================================================
require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$members = $pdo->query(
    "SELECT id, name FROM team_members WHERE pending_start = 1 AND status = 'inactive' AND start_date IS NOT NULL AND start_date <= CURDATE()"
)->fetchAll(PDO::FETCH_ASSOC);

$update = $pdo->prepare("UPDATE team_members SET status = 'active', pending_start = 0 WHERE id = :id");
$log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'Team member activated', 'team', :details, 'success', 'cron')");

$count = 0;
foreach ($members as $m) {
    $update->execute([':id' => $m['id']]);
    $log->execute([':details' => "{$m['name']} switched to active — their start date arrived."]);
    $count++;
}

echo "Activated {$count} team member(s) on their start date.\n";
