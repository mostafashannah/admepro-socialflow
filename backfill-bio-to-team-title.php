<?php
// One-off: copies each user_profiles.bio into the matching team_members.title
// wherever the team member's title is still blank. Needed because Bio/Title
// on the Account page used to only save to user_profiles, never to
// team_members.title — which is the field contact reports and Mai actually
// read a person's job title from. Safe to run more than once.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$profiles = $pdo->query("SELECT user_email, bio FROM user_profiles WHERE bio IS NOT NULL AND bio != ''")->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
foreach ($profiles as $p) {
    $stmt = $pdo->prepare("UPDATE team_members SET title = :title WHERE email = :email AND (title IS NULL OR title = '')");
    $stmt->execute([':title' => $p['bio'], ':email' => $p['user_email']]);
    if ($stmt->rowCount() > 0) {
        echo "Set title for {$p['user_email']} -> {$p['bio']}\n";
        $updated++;
    }
}
echo json_encode(['ok' => true, 'profiles_scanned' => count($profiles), 'team_members_updated' => $updated]) . "\n";
