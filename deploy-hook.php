<?php
// ================================================================
// SocialFlow — GitHub deploy webhook receiver
//
// GitHub calls this on every push (configured in the repo's
// Settings → Webhooks). On a verified push to `main` it runs
// `git pull origin main` so the live VPS always matches main
// without anyone needing shell access to deploy.
//
// Setup on the VPS (one-time):
//   1. Add to config.php:  define('DEPLOY_WEBHOOK_SECRET', '<random secret>');
//   2. In the GitHub repo: Settings → Webhooks → Add webhook
//        Payload URL:  https://socialflow.admepro.com/deploy-hook.php
//        Content type: application/json
//        Secret:       <same random secret>
//        Events:       Just the push event
// ================================================================

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!defined('DEPLOY_WEBHOOK_SECRET') || !DEPLOY_WEBHOOK_SECRET) {
    http_response_code(500);
    echo json_encode(['error' => 'DEPLOY_WEBHOOK_SECRET not configured']);
    exit;
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, DEPLOY_WEBHOOK_SECRET);
if (!$signature || !hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$data = json_decode($payload, true);
$ref = $data['ref'] ?? '';

if ($ref !== 'refs/heads/main') {
    echo json_encode(['ok' => true, 'skipped' => "ref $ref is not main"]);
    exit;
}

$repoDir = __DIR__;
$logFile = __DIR__ . '/deploy.log';

$output = [];
$cmd = 'cd ' . escapeshellarg($repoDir) . ' && git pull origin main 2>&1';
exec($cmd, $output, $exitCode);

$logLine = '[' . date('Y-m-d H:i:s') . "] exit={$exitCode}\n" . implode("\n", $output) . "\n\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

echo json_encode([
    'ok' => $exitCode === 0,
    'exit_code' => $exitCode,
    'output' => $output,
]);
