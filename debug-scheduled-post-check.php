<?php
// READ-ONLY diagnostic — shows every 'scheduled' stage post for Arabia
// Uniform (or all clients if none matches), plus that client's
// auto_publish_enabled setting, to see why auto-publish.php found 0
// candidates.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$client = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%Arabia%'")->fetch(PDO::FETCH_ASSOC);
echo "=== Client ===\n" . json_encode($client) . "\n";

if ($client) {
    $ci = $pdo->prepare("SELECT client_id, auto_publish_enabled, auto_schedule_enabled FROM client_intelligence WHERE client_id = ?");
    $ci->execute([$client['id']]);
    echo "\n=== client_intelligence row ===\n" . json_encode($ci->fetch(PDO::FETCH_ASSOC)) . "\n";
}

echo "\n=== All posts currently in 'scheduled' stage ===\n";
$posts = $pdo->query("SELECT id, title, client_name, platform, task_type, stage, scheduled_date, scheduled_time, publish_attempts, publish_error FROM posts WHERE stage = 'scheduled' ORDER BY scheduled_date DESC, scheduled_time DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
foreach ($posts as $p) echo json_encode($p) . "\n";
if (!$posts) echo "(none)\n";

echo "\nServer time now: " . date('Y-m-d H:i:s') . "\n";
