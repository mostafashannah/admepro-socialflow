<?php
// ================================================================
// SocialFlow — OpenAI Proxy (chat completions, Whisper transcription,
// image generation). Same pattern as ai-proxy.php (Anthropic): the API
// key lives only here, server-side (config.php), never in the frontend
// bundle. Three modes, chosen by ?mode=:
//   (default)     -> POST /v1/chat/completions      (GPT text generation)
//   ?mode=transcribe -> POST /v1/audio/transcriptions (Whisper, multipart audio)
//   ?mode=image      -> POST /v1/images/generations   (gpt-image-1)
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(["error"=>"Method not allowed"]);exit;}

require_once __DIR__ . '/config.php';
$OPENAI_KEY = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
if(!$OPENAI_KEY){ http_response_code(500); echo json_encode(["error"=>"OPENAI_API_KEY is not configured"]); exit; }

$mode = $_GET['mode'] ?? '';

function openai_curl($url, $headers, $body, $isMultipart=false) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER     => $headers,
  ]);
  $res    = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err    = curl_error($ch);
  curl_close($ch);
  if($err){ return [500, json_encode(["error"=>"cURL error: $err"])]; }
  return [$status, $res ?: json_encode(["error"=>"No response from OpenAI"])];
}

if($mode === 'transcribe') {
  // Frontend sends the recorded audio as multipart/form-data with a
  // single "audio" file field — forward it straight to Whisper.
  if(empty($_FILES['audio'])) { http_response_code(400); echo json_encode(["error"=>"Missing audio file"]); exit; }
  $cfile = new CURLFile($_FILES['audio']['tmp_name'], $_FILES['audio']['type'] ?: 'audio/webm', 'audio.webm');
  $body = ['model' => 'whisper-1', 'file' => $cfile, 'response_format' => 'json'];
  [$status, $res] = openai_curl(
    "https://api.openai.com/v1/audio/transcriptions",
    ["Authorization: Bearer $OPENAI_KEY"],
    $body
  );
  http_response_code($status);
  echo $res;
  exit;
}

if($mode === 'image') {
  $body = file_get_contents("php://input");
  [$status, $res] = openai_curl(
    "https://api.openai.com/v1/images/generations",
    ["Authorization: Bearer $OPENAI_KEY", "Content-Type: application/json"],
    $body
  );
  http_response_code($status);
  echo $res;
  exit;
}

// Default: chat completions passthrough.
$body = file_get_contents("php://input");
if(!json_decode($body, true)){ http_response_code(400); echo json_encode(["error"=>"Invalid JSON body"]); exit; }
[$status, $res] = openai_curl(
  "https://api.openai.com/v1/chat/completions",
  ["Authorization: Bearer $OPENAI_KEY", "Content-Type: application/json"],
  $body
);
http_response_code($status);
echo $res;
