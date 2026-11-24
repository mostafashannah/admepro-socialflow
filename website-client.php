<?php
/**
 * website-client.php — per-client website connection helpers (the "Test connection" button of the Website integration).
 * POST {action:"test", credentials:{page_id:"owner/repo", access_token, branch, content_root, posts_dir}} -> {ok, repo, articles, folder, sample_keys | error}
 * The token is only used for this one request; nothing is stored here. Admins and the account team only.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/website-publish-lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$a = authResolve(); $s = $a['session'] ?? null;
if (!$s || ($s['user_type'] ?? '') !== 'team' || !in_array($s['role'] ?? '', ['admin', 'account_manager', 'account_director', 'account_executive'], true)) { http_response_code(403); echo json_encode(['error' => 'Not allowed']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (($in['action'] ?? '') === 'test') {
    $c = is_array($in['credentials'] ?? null) ? $in['credentials'] : [];
    echo json_encode(website_test_connection($c), JSON_UNESCAPED_UNICODE); exit;
}
http_response_code(400); echo json_encode(['error' => 'Unknown action']);
