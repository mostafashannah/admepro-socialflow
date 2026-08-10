<?php
// READ-ONLY diagnostic — the daily WhatsApp report told Menna "Bino hasn't
// posted in 20-25 days" but the user says Bino actually posted yesterday.
// The cadence check in mai-daily-report-cron.php reads MAX(published_at)
// from posts WHERE client_id=:cid AND stage='published' — checking
// whether yesterday's Bino post is really in 'published' stage with a real
// published_at, or stuck in another stage/missing that timestamp (a known
// past bug: manually moving a post to Published didn't always stamp
// published_at).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$client = $pdo->query("SELECT id, name, account_manager_id FROM clients WHERE name LIKE '%Bino%'")->fetch(PDO::FETCH_ASSOC);
echo "=== Client ===\n" . json_encode($client) . "\n";
if (!$client) exit;

$posts = $pdo->prepare(
    "SELECT id, title, stage, platform, scheduled_date, published_at, created_at
     FROM posts WHERE client_id = :cid
     ORDER BY COALESCE(published_at, scheduled_date, created_at) DESC
     LIMIT 15"
);
$posts->execute([':cid' => $client['id']]);
echo "\n=== Bino's most recent posts (any stage) ===\n";
foreach ($posts->fetchAll(PDO::FETCH_ASSOC) as $p) echo json_encode($p) . "\n";

$maxPub = $pdo->prepare("SELECT MAX(published_at) FROM posts WHERE client_id = :cid AND stage = 'published'");
$maxPub->execute([':cid' => $client['id']]);
echo "\nMAX(published_at) where stage='published': " . $maxPub->fetchColumn() . "\n";

$am = null;
if (!empty($client['account_manager_id'])) {
    $amIds = json_decode($client['account_manager_id'], true);
    if (!is_array($amIds)) $amIds = [$client['account_manager_id']];
    foreach ($amIds as $id) {
        $r = $pdo->prepare("SELECT name, email, role FROM team_members WHERE id = ?");
        $r->execute([$id]);
        echo "Assigned AM: " . json_encode($r->fetch(PDO::FETCH_ASSOC)) . "\n";
    }
}
