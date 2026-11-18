<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// Asks TikTok what happened to a post: php tiktok-status.php <post id>   (looks up its publish_id; never prints tokens)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tiktok-lib.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$postId = $argv[1] ?? '';
$st = $pdo->prepare("SELECT client_id, stage, external_post_id, platform_post_ids, published_at, publish_error FROM posts WHERE id = ?"); $st->execute([$postId]);
$p = $st->fetch(PDO::FETCH_ASSOC);
if (!$p) { echo "No such post\n"; exit(1); }
$ids = json_decode($p['platform_post_ids'] ?? '{}', true) ?: [];
$pid = $ids['tiktok'] ?? $p['external_post_id'];
echo "stage={$p['stage']} published_at={$p['published_at']} publish_error=" . ($p['publish_error'] ?: '-') . "\nstored id=" . ($pid ?: '(none)') . "\n";
if (!$pid) { echo "No TikTok id was stored: TikTok never accepted this upload.\n"; exit; }
$i = $pdo->prepare("SELECT id, credentials FROM integrations WHERE app_key='tiktok' AND status='active' ORDER BY (client_id = ?) DESC LIMIT 1"); $i->execute([$p['client_id']]);
$row = $i->fetch(PDO::FETCH_ASSOC);
$creds = json_decode($row['credentials'] ?? '{}', true) ?: [];
$tok = tiktok_get_fresh_token($pdo, $row['id'], $creds) ?? ($creds['access_token'] ?? '');
[$c, $r] = tiktok_curl(TIKTOK_API_BASE . '/post/publish/status/fetch/', 'POST', ['publish_id' => $pid], $tok);
echo "TikTok status call: HTTP $c\n" . json_encode($r['data'] ?? $r, JSON_PRETTY_PRINT) . "\n";
