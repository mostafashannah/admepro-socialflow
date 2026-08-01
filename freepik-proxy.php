<?php
// ================================================================
// SocialFlow — Freepik/Magnific Proxy. Same pattern as openai-proxy.php:
// the API key lives only here, server-side (config.php), never in the
// frontend bundle. Two modes, chosen by ?mode=:
//   ?mode=upscale         -> POST /v1/ai/image-upscaler        (start a task)
//   ?mode=upscale_status  -> GET  /v1/ai/image-upscaler/{id}   (poll a task)
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}

require_once __DIR__ . '/config.php';
$FREEPIK_KEY = defined('FREEPIK_API_KEY') ? FREEPIK_API_KEY : '';
if(!$FREEPIK_KEY){ http_response_code(500); echo json_encode(["error"=>"FREEPIK_API_KEY is not configured"]); exit; }

function freepik_curl($url, $method, $apiKey, $body=null) {
  $ch = curl_init($url);
  $headers = ["x-freepik-api-key: $apiKey"];
  $opts = [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
  ];
  if($body !== null) {
    $headers[] = "Content-Type: application/json";
    $opts[CURLOPT_POSTFIELDS] = $body;
  }
  $opts[CURLOPT_HTTPHEADER] = $headers;
  curl_setopt_array($ch, $opts);
  $res    = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err    = curl_error($ch);
  curl_close($ch);
  if($err){ return [500, json_encode(["error"=>"cURL error: $err"])]; }
  return [$status, $res ?: json_encode(["error"=>"No response from Freepik"])];
}

$mode = $_GET['mode'] ?? '';

if($mode === 'upscale') {
  if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(["error"=>"Method not allowed"]);exit;}
  $body = file_get_contents("php://input");
  $decoded = json_decode($body, true);
  if(!$decoded || empty($decoded['image'])) { http_response_code(400); echo json_encode(["error"=>"Missing image"]); exit; }
  $payload = json_encode([
    'image'         => $decoded['image'], // base64, no data: prefix
    'scale_factor'  => $decoded['scale_factor'] ?? '2x',
    'optimized_for' => $decoded['optimized_for'] ?? 'standard',
    'engine'        => $decoded['engine'] ?? 'automatic',
  ]);
  [$status, $res] = freepik_curl("https://api.freepik.com/v1/ai/image-upscaler", "POST", $FREEPIK_KEY, $payload);
  http_response_code($status);
  echo $res;
  exit;
}

if($mode === 'upscale_status') {
  $taskId = $_GET['task_id'] ?? '';
  if(!$taskId) { http_response_code(400); echo json_encode(["error"=>"Missing task_id"]); exit; }
  [$status, $res] = freepik_curl("https://api.freepik.com/v1/ai/image-upscaler/".urlencode($taskId), "GET", $FREEPIK_KEY);
  http_response_code($status);
  echo $res;
  exit;
}

// Generic passthrough for Freepik's other async AI endpoints (text-to-image
// models like Mystic/Seedream/Flux, and image/text-to-video models like
// Kling/MiniMax) — they all share the same "POST creates a task, GET polls
// it" shape, just under different endpoint slugs, so one generic pair of
// modes covers all of them instead of hardcoding each model server-side.
$ALLOWED_ENDPOINT_PREFIXES = ['mystic', 'text-to-image/', 'image-to-video/', 'text-to-video/', 'image-upscaler'];
function endpoint_allowed($ep, $prefixes) {
  foreach($prefixes as $p) { if($ep === $p || strpos($ep, $p) === 0) return true; }
  return false;
}

if($mode === 'create') {
  if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(["error"=>"Method not allowed"]);exit;}
  $body = json_decode(file_get_contents("php://input"), true);
  $endpoint = $body['endpoint'] ?? '';
  $payload = $body['payload'] ?? null;
  if(!$endpoint || !endpoint_allowed($endpoint, $ALLOWED_ENDPOINT_PREFIXES)) { http_response_code(400); echo json_encode(["error"=>"Invalid or missing endpoint"]); exit; }
  [$status, $res] = freepik_curl("https://api.freepik.com/v1/ai/".$endpoint, "POST", $FREEPIK_KEY, json_encode($payload));
  http_response_code($status);
  echo $res;
  exit;
}

if($mode === 'status') {
  $endpoint = $_GET['endpoint'] ?? '';
  $taskId = $_GET['task_id'] ?? '';
  if(!$endpoint || !endpoint_allowed($endpoint, $ALLOWED_ENDPOINT_PREFIXES) || !$taskId) { http_response_code(400); echo json_encode(["error"=>"Invalid or missing endpoint/task_id"]); exit; }
  [$status, $res] = freepik_curl("https://api.freepik.com/v1/ai/".$endpoint."/".urlencode($taskId), "GET", $FREEPIK_KEY);
  http_response_code($status);
  echo $res;
  exit;
}

http_response_code(400);
echo json_encode(["error"=>"Unknown mode"]);
