<?php
require_once 'config.php';
require_once 'meta-lib.php';
require_once 'linkedin-lib.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

$data         = json_decode(file_get_contents("php://input"), true);
$platform     = strtolower(trim($data["platform"]     ?? ""));
$page_id      = trim($data["page_id"]      ?? "");
$access_token = trim($data["access_token"] ?? "");
$message      = trim($data["message"]      ?? "");
$image_url    = trim($data["image_url"]    ?? "");
$story_image_url = trim($data["story_image_url"] ?? ""); // Instagram-only, optional
$scheduled_at = trim($data["scheduled_at"] ?? ""); // ISO-8601, optional

if (!$platform || !$page_id || !$access_token || !$message) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields: platform, page_id, access_token, message"]);
    exit;
}

if (!in_array($platform, ["facebook", "instagram", "linkedin"])) {
    http_response_code(400);
    echo json_encode(["error" => "Unsupported platform. Supported: facebook, instagram, linkedin"]);
    exit;
}

if ($platform === "linkedin") {
    [$http_code, $response] = linkedin_publish($page_id, $access_token, $message, $image_url);
} else {
    [$http_code, $response] = meta_publish($platform, $page_id, $access_token, $message, $image_url, $scheduled_at ?: null, $story_image_url ?: null);
}
http_response_code($http_code);
echo json_encode($response);
