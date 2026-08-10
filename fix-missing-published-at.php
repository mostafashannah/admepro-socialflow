<?php
// One-off correction — posts genuinely in stage='published' but with a
// NULL published_at (found via debug-bino-cadence.php: "Spider Man Trend",
// "Gamma Innovation Post", "brand" for Bino, likely others across other
// clients too) corrupt every date-filtered query downstream (Mai's
// cadence check picked an ancient MAX(published_at) instead of the real
// recent one, understating how active the client actually is). Best
// available real signal for when they actually went live is scheduled_date
// (if set) else created_at — not perfect, but far better than leaving it
// NULL and silently excluded from every "how recent" query.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$rows = $pdo->query(
    "SELECT id, title, client_name, scheduled_date, created_at
     FROM posts WHERE stage = 'published' AND published_at IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);
echo "=== Published posts missing published_at (across all clients) ===\n";
foreach ($rows as $r) echo json_encode($r) . "\n";
if (!$rows) { echo "(none found)\n"; exit; }

$upd = $pdo->prepare("UPDATE posts SET published_at = COALESCE(scheduled_date, created_at) WHERE id = ?");
foreach ($rows as $r) $upd->execute([$r['id']]);
echo "\nBackfilled published_at for " . count($rows) . " post(s).\n";
