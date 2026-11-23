<?php
/**
 * publish-reason.php — why a platform of a post failed to publish, read from the auto-publisher's own log (integration_logs).
 * GET ?post_id=<id> -> {"reasons": {"instagram": "message", "twitter": "message"}}. Needs a login session.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
header('Content-Type: application/json');
if (!authResolve()) { http_response_code(401); echo json_encode(['error' => 'Not signed in']); exit; }
$postId = preg_replace('/[^A-Za-z0-9-]/', '', (string)($_GET['post_id'] ?? ''));
if ($postId === '') { http_response_code(400); echo json_encode(['error' => 'post_id?']); exit; }
$out = [];
try {
    $pdo = authPdo();
    $st = $pdo->prepare("SELECT payload_summary, error FROM integration_logs WHERE status = 'error' AND payload_summary LIKE :p ORDER BY created_at DESC LIMIT 40");
    $st->execute([':p' => "Auto-publish post $postId (%"]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!preg_match('/\(([a-z]+)\)\s*$/', (string)$r['payload_summary'], $m)) continue;
        $pl = $m[1]; if (isset($out[$pl])) continue;
        $e = json_decode((string)$r['error'], true);
        $msg = is_array($e) ? ($e['error']['message'] ?? $e['error'] ?? $e['message'] ?? ($e['detail']['error']['message'] ?? $e['detail']['error'] ?? null)) : (string)$r['error'];
        if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        $out[$pl] = mb_substr((string)($msg ?: $r['error']), 0, 400);
    }
} catch (Throwable $e) { /* the log may not exist: no reasons */ }

// No logged reason for a platform: diagnose it now (text length for X, live token check for Instagram / Facebook, missing connection).
try {
    $pdo = $pdo ?? authPdo();
    $ps = $pdo->prepare("SELECT client_id, platforms, platform, caption, hashtags, x_caption, published_platforms FROM posts WHERE id = :id LIMIT 1");
    $ps->execute([':id' => $postId]); $post = $ps->fetch(PDO::FETCH_ASSOC);
    if ($post) {
        $all = json_decode((string)$post['platforms'], true) ?: array_filter([$post['platform']]);
        $done = json_decode((string)$post['published_platforms'], true) ?: [];
        foreach ($all as $pl) {
            if (in_array($pl, $done, true) || isset($out[$pl])) continue;
            if ($pl === 'twitter') {
                $xt = trim((string)$post['x_caption']) !== '' ? trim($post['x_caption']) : trim(trim((string)$post['caption']) . "\n\n" . trim((string)$post['hashtags']));
                if (mb_strlen($xt) > 280) { $out[$pl] = 'The text is ' . mb_strlen($xt) . ' characters and X allows 280. Write a short X caption (the ✨ Sara button can make it) and republish.'; continue; }
            }
            // the connection this post would use
            $integ = null;
            if (!empty($post['client_id'])) { $q = $pdo->prepare("SELECT credentials FROM integrations WHERE app_key = :p AND status = 'active' AND client_id = :c LIMIT 1"); $q->execute([':p' => $pl, ':c' => $post['client_id']]); $integ = $q->fetch(PDO::FETCH_ASSOC); }
            if (!$integ) { $q = $pdo->prepare("SELECT credentials FROM integrations WHERE app_key = :p AND status = 'active' AND (client_id IS NULL OR client_id = '') LIMIT 1"); $q->execute([':p' => $pl]); $integ = $q->fetch(PDO::FETCH_ASSOC); }
            if (!$integ) { $out[$pl] = 'No active ' . $pl . ' connection for this client. Connect it in Settings → Integrations.'; continue; }
            $creds = json_decode((string)$integ['credentials'], true) ?: [];
            $tok = trim((string)($creds['access_token'] ?? ''));
            if (in_array($pl, ['instagram', 'facebook'], true) && $tok !== '' && $tok !== 'zernio') {
                $host = (strpos($tok, 'IGAA') === 0) ? 'graph.instagram.com' : 'graph.facebook.com';
                $ch = curl_init("https://$host/" . (defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v23.0') . '/me?fields=id&access_token=' . urlencode($tok));
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12]);
                $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
                if (isset($r['error']['message'])) $out[$pl] = $r['error']['message'] . ' — reconnect ' . ucfirst($pl) . ' in Settings → Integrations, then republish.';
            }
        }
    }
} catch (Throwable $e) { /* diagnosis is best effort */ }
echo json_encode(['reasons' => (object)$out], JSON_UNESCAPED_UNICODE);
