<?php
// ================================================================
// SocialFlow — Anthropic Claude AI Proxy
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(["error"=>"Method not allowed"]);exit;}

// PHP's default max_execution_time (usually 30s) kills the process mid-request
// for large AI calls (long system prompts, high max_tokens, web search).
// The CURLOPT_TIMEOUT of 300s already caps the actual Anthropic round-trip,
// so lifting the PHP limit here is safe.
set_time_limit(300);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-lib.php';
if (!authResolve()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error' => 'Not signed in']); exit; } // needs a login session (or the app key until stage 3)
// Guest passes (public link pages): only the careers/complete-application CV review, 3 calls per pass.
if (($__a = authResolve()) && $__a['kind'] === 'session' && ($__a['session']['user_type'] ?? '') === 'guest') {
    require_once __DIR__ . '/guest-policy.php';
    if (!in_array($__a['session']['role'], ['careers', 'complete'], true) || !guestQuota(authPdo(), $__a['session'], 'ai', 3)) { http_response_code(429); echo json_encode(['error' => 'Limit reached']); exit; }
}
require_once __DIR__ . '/ai-outage-notify.php';
$ANTHROPIC_KEY = ANTHROPIC_API_KEY;

$rawInput = file_get_contents("php://input");
// A large PDF/image attachment (base64-inflated ~33% over the original file
// size) can push the JSON body past PHP's post_max_size — when that happens
// PHP silently empties php://input instead of erroring, so this used to
// surface as a misleading generic "Invalid JSON body" with no indication
// the file was ever the problem, right after the app had already cleared
// the attachment from the composer on send. Content-Length still reflects
// what the browser actually tried to send, so a mismatch between that and
// an empty body is the tell.
if($rawInput === '' && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0){
  http_response_code(413);
  echo json_encode(["error"=>"Request body too large for this server's PHP config (post_max_size) — attachment(s) never reached the AI. Ask an admin to raise post_max_size/upload_max_filesize, or use a smaller file."]);
  exit;
}
$body = json_decode($rawInput, true);
if(!$body){
  http_response_code(400);
  echo json_encode(["error"=>"Invalid JSON body"]);
  exit;
}

function sfAnthropicCall(array $body, string $key) {
  $payload = json_encode($body);

$ch = curl_init("https://api.anthropic.com/v1/messages");
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_RETURNTRANSFER => true,
  // 60s was too tight for Pro's real requests (large system prompt + high
  // max_tokens + web search) — Anthropic regularly needs longer, and every
  // overrun surfaced in-app as an opaque "AI error: API error".
  CURLOPT_TIMEOUT        => 300,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_HTTPHEADER     => [
    "x-api-key: $key",
    "anthropic-version: 2023-06-01",
    "Content-Type: application/json",
  ],
]);
$res    = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

  return [$res, $status, $err];
}
[$res, $status, $err] = sfAnthropicCall($body, $ANTHROPIC_KEY);
// If the account rejects a newer model id, retry once on the previous
// known-good Sonnet instead of failing every AI call in the app.
if (!$err && ($status === 400 || $status === 404 || $status === 422) && ($body['model'] ?? '') === 'claude-sonnet-5-5') {
  error_log("[ai-proxy] claude-sonnet-5-5 rejected — falling back to claude-sonnet-4-6: " . substr((string)$res, 0, 300));
  $body['model'] = 'claude-sonnet-4-6';
  [$res, $status, $err] = sfAnthropicCall($body, $ANTHROPIC_KEY);
}

if($err){ http_response_code(500); echo json_encode(["error"=>"cURL error: $err"]); exit; }
if($status < 200 || $status >= 300){
  // "The string did not match the expected pattern" reports have recurred
  // with no visibility into WHICH field Anthropic actually rejected —
  // log the full error body server-side so it can be diagnosed instead of
  // guessed at again.
  error_log("[ai-proxy] Anthropic API error, HTTP $status: " . substr((string)$res, 0, 2000));
}
recordAiCallResult('Anthropic', $status, (string)$res);
http_response_code($status >= 200 && $status < 300 ? 200 : $status);
echo $res ?: json_encode(["error"=>"No response from Anthropic"]);
