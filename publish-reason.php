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
echo json_encode(['reasons' => (object)$out], JSON_UNESCAPED_UNICODE);
