<?php
require_once 'config.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, apikey, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

// Same shared-key check as api.php — internal-only endpoint (only ever
// called from the logged-in app), but had no server-side auth of its own.
$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$to   = trim($data["to"]   ?? "");
$body = trim($data["body"] ?? "");

if (!$to || !$body) {
    http_response_code(400);
    echo json_encode(["error" => "Missing 'to' or 'body'"]);
    exit;
}

// Normalise number: strip spaces/dashes, ensure leading +
$to = preg_replace('/[\s\-\(\)]+/', '', $to);
if (!str_starts_with($to, '+')) $to = '+' . $to;
// Remove non-digit chars except leading +
$to = '+' . preg_replace('/\D/', '', $to);

$phone_id     = defined('WA_PHONE_ID')     ? WA_PHONE_ID     : "";
$wa_token     = defined('WA_ACCESS_TOKEN') ? WA_ACCESS_TOKEN : "";

if (!$phone_id || !$wa_token) {
    http_response_code(503);
    echo json_encode(["error" => "WhatsApp not configured (WA_PHONE_ID / WA_ACCESS_TOKEN missing)"]);
    exit;
}

$endpoint = "https://graph.facebook.com/v19.0/{$phone_id}/messages";
$payload  = json_encode([
    "messaging_product" => "whatsapp",
    "to"                => $to,
    "type"              => "text",
    "text"              => ["body" => $body],
]);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "Authorization: Bearer {$wa_token}",
    ],
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    http_response_code(502);
    echo json_encode(["error" => "cURL error: {$curl_err}"]);
    exit;
}

http_response_code($http_code);
echo $response;
