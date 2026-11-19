<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// Find out WHY Instagram rejects reels for one client. Publishes NOTHING: it only asks Instagram to process a video
// (creates a "container") and prints what Instagram answers. The container expires by itself.
//
//   php ig-reel-test.php "Bino" make          builds a tiny known-good test reel with ffmpeg and tries that
//   php ig-reel-test.php "Bino" <video url>   tries the video at that address (e.g. the real reel)
//
// Compare the two: if the known-good test is accepted but the real reel is rejected, the real FILE is the problem.
// If BOTH are rejected, the problem is the Instagram ACCOUNT / connection (account type, permissions), not the file.
// Tokens are never printed.
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/meta-lib.php';
$client = $argv[1] ?? ''; $what = $argv[2] ?? '';
if ($client === '' || $what === '') { echo "Usage: php ig-reel-test.php \"Client name\" make|<video url>\n"; exit(1); }
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = $pdo->prepare("SELECT name, client_name, credentials FROM integrations WHERE app_key = 'instagram' AND status = 'active' AND client_name LIKE :c LIMIT 1");
$q->execute([':c' => '%' . $client . '%']); $ig = $q->fetch(PDO::FETCH_ASSOC);
if (!$ig) { echo "No active Instagram connection found for \"{$client}\".\n"; exit(1); }
$creds = json_decode($ig['credentials'], true) ?: []; $uid = trim((string)($creds['page_id'] ?? '')); $tok = trim((string)($creds['access_token'] ?? ''));
echo "Connection: {$ig['name']} (client: {$ig['client_name']})\n";

// 1) What kind of Instagram account is it? Reels need a professional (Business / Creator) account.
$me = meta_curl_get('https://graph.instagram.com/' . META_GRAPH_VERSION . '/me?' . http_build_query(['fields' => 'id,username,account_type', 'access_token' => $tok]))[1] ?? [];
echo "Account: " . json_encode(['username' => $me['username'] ?? null, 'account_type' => $me['account_type'] ?? null, 'error' => $me['error']['message'] ?? null], JSON_UNESCAPED_UNICODE) . "\n";

// 2) The video to try
$url = $what;
if ($what === 'make') {
    $dir = __DIR__ . '/uploads/socialflow-media/design/picker'; $name = 'sf_test_reel_' . time() . '.mp4';
    $cmd = 'ffmpeg -v error -y -f lavfi -i testsrc=size=1080x1920:rate=30 -f lavfi -i sine=frequency=440 -t 6 -c:v libx264 -pix_fmt yuv420p -profile:v high -c:a aac -b:a 128k -shortest -movflags +faststart ' . escapeshellarg("$dir/$name") . ' 2>&1';
    exec($cmd, $out, $rc);
    if ($rc !== 0 || !is_file("$dir/$name")) { echo "Could not build the test video: " . implode("\n", $out) . "\n"; exit(1); }
    @chmod("$dir/$name", 0644);
    $url = 'https://socialflow.admepro.com/storage/public/socialflow-media/design/picker/' . $name;
    echo "Built a 6-second test reel (H.264 + AAC, 1080x1920, web-ready): {$url}\n";
}
echo "Asking Instagram to process: {$url}\n";
[$code, $resp] = ig_create_container(META_GRAPH_VERSION, $uid, $tok, $url, 'test (not published)', 'REELS');
echo "Create container -> HTTP {$code}: " . json_encode($resp['error'] ?? ['id' => $resp['id'] ?? null], JSON_UNESCAPED_UNICODE) . "\n";
if ($code !== 200 || empty($resp['id'])) { echo "Instagram would not even accept the request above. That message is the reason.\n"; exit(1); }

// 3) Watch the processing for up to 6 minutes (nothing is published)
$cid = $resp['id']; $t0 = time();
while (time() - $t0 < 360) {
    sleep(10);
    $st = meta_curl_get('https://graph.instagram.com/' . META_GRAPH_VERSION . "/{$cid}?" . http_build_query(['fields' => 'status_code,status', 'access_token' => $tok]))[1] ?? [];
    echo '+' . (time() - $t0) . 's  ' . json_encode(['status_code' => $st['status_code'] ?? null, 'status' => $st['status'] ?? null, 'error' => $st['error']['message'] ?? null], JSON_UNESCAPED_UNICODE) . "\n";
    if (in_array($st['status_code'] ?? '', ['FINISHED', 'ERROR', 'EXPIRED'], true) || isset($st['error'])) break;
}
echo "\nDone. Nothing was published.\n";
