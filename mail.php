<?php
// ================================================================
// SocialFlow — Resend Email Proxy
// Domain: admepro.com (verified in Resend)
// ================================================================
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(200);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(["error"=>"Method not allowed"]);exit;}

require_once __DIR__ . '/config.php';
$RESEND_KEY = RESEND_API_KEY;
$FROM_NAME  = "SocialFlow by Admepro";
$FROM_EMAIL = "noreply@admepro.com";

$body = json_decode(file_get_contents("php://input"), true);
if(!$body || !isset($body['to'], $body['subject'], $body['html'])){
  http_response_code(400);
  echo json_encode(["error"=>"Missing required fields: to, subject, html"]);
  exit;
}

$to      = $body['to'];
$subject = $body['subject'];
$html    = $body['html'];
$fromName= isset($body['from_name']) ? $body['from_name'] : $FROM_NAME;

$payload = json_encode([
  "from"    => "$fromName <$FROM_EMAIL>",
  "to"      => is_array($to) ? $to : [$to],
  "subject" => $subject,
  "html"    => $html,
]);

$ch = curl_init("https://api.resend.com/emails");
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER     => [
    "Authorization: Bearer $RESEND_KEY",
    "Content-Type: application/json",
  ],
]);
$res    = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($status >= 200 && $status < 300 ? 200 : $status);
echo $res ?: json_encode(["error"=>"No response from Resend"]);
