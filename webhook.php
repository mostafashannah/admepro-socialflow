<?php
// GitHub webhook auto-deploy.
// Configure in GitHub: repo → Settings → Webhooks → Add webhook
//   Payload URL: https://yourdomain.com/webhook.php
//   Content type: application/json
//   Secret: same value as GITHUB_WEBHOOK_SECRET in config.php
//   Events: just the "push" event
//
// On every push to main, GitHub calls this endpoint, which verifies the
// HMAC signature and then runs `git pull origin main` in this directory.

require __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

if (!defined('GITHUB_WEBHOOK_SECRET') || GITHUB_WEBHOOK_SECRET === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING') {
    http_response_code(500);
    echo json_encode(['error' => 'GITHUB_WEBHOOK_SECRET not configured']);
    exit;
}

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $payload, GITHUB_WEBHOOK_SECRET);

if (!$signature || !hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

$data = json_decode($payload, true);
$ref  = $data['ref'] ?? '';

if ($ref !== 'refs/heads/main') {
    echo json_encode(['ok' => true, 'skipped' => "ref $ref is not main"]);
    exit;
}

$repoDir = __DIR__;
$cmd = sprintf('cd %s && git pull origin main 2>&1', escapeshellarg($repoDir));
exec($cmd, $output, $exitCode);
$log = sprintf("[%s] exit=%d\n%s\n\n", date('c'), $exitCode, implode("\n", $output));
file_put_contents(__DIR__ . '/deploy.log', $log, FILE_APPEND);

echo json_encode(['ok' => $exitCode === 0, 'output' => $output]);
