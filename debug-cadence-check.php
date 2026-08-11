<?php
// Read-only — checks what the daily-report cron's cadence query actually
// sees for SLVR Communities and Bino, since both were reported as flagged
// "behind schedule" in Mai's daily WhatsApp report despite the user saying
// they posted recently (SLVR yesterday, Bino recently) — need to see
// whether this is a real data gap (stage=published but published_at
// missing/stale) or something else before touching any code.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Server NOW(): " . $pdo->query("SELECT NOW()")->fetchColumn() . "\n\n";

$postCols = array_column($pdo->query("SHOW COLUMNS FROM posts")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$createdCol = in_array('created_at', $postCols) ? 'created_at' : (in_array('created_date', $postCols) ? 'created_date' : null);
echo "posts table created-timestamp column: " . var_export($createdCol, true) . "\n\n";

foreach (['SLVR', 'Bino'] as $needle) {
    $client = $pdo->prepare("SELECT id, name FROM clients WHERE name LIKE :n LIMIT 1");
    $client->execute([':n' => "%{$needle}%"]);
    $c = $client->fetch(PDO::FETCH_ASSOC);
    if (!$c) { echo "=== {$needle}: client not found ===\n\n"; continue; }
    echo "=== {$c['name']} ({$c['id']}) ===\n";

    $intel = $pdo->prepare("SELECT posting_frequency FROM client_intelligence WHERE client_id = :cid LIMIT 1");
    $intel->execute([':cid' => $c['id']]);
    echo "posting_frequency: " . var_export($intel->fetchColumn(), true) . "\n";

    $recent = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE client_id = :cid AND stage = 'published' AND published_at >= (NOW() - INTERVAL 7 DAY)");
    $recent->execute([':cid' => $c['id']]);
    echo "published in last 7 days (per cron query): " . $recent->fetchColumn() . "\n";

    $orderExpr = $createdCol ? "COALESCE(published_at, {$createdCol})" : "published_at";
    $selectExtra = $createdCol ? ", {$createdCol} AS created_ts" : "";
    $stmt = $pdo->prepare("SELECT id, title, stage, published_at, scheduled_date{$selectExtra} FROM posts WHERE client_id = :cid AND (stage = 'published' OR published_at IS NOT NULL) ORDER BY {$orderExpr} DESC LIMIT 8");
    $stmt->execute([':cid' => $c['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Most recent published/published_at rows:\n";
    foreach ($rows as $r) {
        echo "  [{$r['stage']}] {$r['title']} | published_at=" . var_export($r['published_at'], true) . " | scheduled_date={$r['scheduled_date']}" . (isset($r['created_ts']) ? " | created={$r['created_ts']}" : "") . "\n";
    }

    // Also check for posts stuck in 'published' stage but client_id/client_name mismatch,
    // which would silently exclude them from the cadence query above.
    $mismatch = $pdo->prepare("SELECT id, title, client_id, client_name, stage, published_at FROM posts WHERE client_name = :n AND (client_id IS NULL OR client_id != :cid) ORDER BY published_at DESC LIMIT 5");
    $mismatch->execute([':n' => $c['name'], ':cid' => $c['id']]);
    $mm = $mismatch->fetchAll(PDO::FETCH_ASSOC);
    if ($mm) {
        echo "Posts matching client_name but with a DIFFERENT/missing client_id (invisible to the cadence query):\n";
        foreach ($mm as $r) echo "  [{$r['stage']}] {$r['title']} | client_id={$r['client_id']} | published_at=" . var_export($r['published_at'], true) . "\n";
    }
    echo "\n";
}
