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

require_once __DIR__ . '/config.php';
$ANTHROPIC_KEY = ANTHROPIC_API_KEY;

$body = json_decode(file_get_contents("php://input"), true);
if(!$body){
  http_response_code(400);
  echo json_encode(["error"=>"Invalid JSON body"]);
  exit;
}

$payload = json_encode($body);

$ch = curl_init("https://api.anthropic.com/v1/messages");
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 60,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_HTTPHEADER     => [
    "x-api-key: $ANTHROPIC_KEY",
    "anthropic-version: 2023-06-01",
    "Content-Type: application/json",
  ],
]);
$res    = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if($err){ http_response_code(500); echo json_encode(["error"=>"cURL error: $err"]); exit; }
http_response_code($status >= 200 && $status < 300 ? 200 : $status);
echo $res ?: json_encode(["error"=>"No response from Anthropic"]);
