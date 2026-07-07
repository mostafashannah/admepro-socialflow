<?php
// ================================================================
// SocialFlow — transcribes an uploaded audio file (voice note recorded
// or picked in the in-app Pro chat) to text via OpenAI Whisper. Same
// underlying transcribeAudio() used for WhatsApp voice notes in
// pro-lib.php, just fed bytes straight from a browser upload instead
// of downloading them from WhatsApp's media CDN first.
// ================================================================
require_once 'config.php';
require_once 'pro-lib.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, apikey, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "Missing or invalid file upload (field name must be 'file')"]);
    exit;
}

if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    http_response_code(503);
    echo json_encode(["error" => "Voice transcription isn't set up yet — add OPENAI_API_KEY to config.php"]);
    exit;
}

$bytes = file_get_contents($_FILES['file']['tmp_name']);
$mime = $_FILES['file']['type'] ?: 'audio/webm';
$text = transcribeAudio($bytes, $mime);

if ($text === null) {
    http_response_code(502);
    echo json_encode(["error" => "Transcription failed — try again"]);
    exit;
}

echo json_encode(["ok" => true, "text" => $text]);
