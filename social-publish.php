<?php
require_once 'config.php';
require_once 'meta-lib.php';
require_once 'linkedin-lib.php';
require_once 'tiktok-lib.php';
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

$data         = json_decode(file_get_contents("php://input"), true);
$platform     = strtolower(trim($data["platform"]     ?? ""));
$page_id      = trim($data["page_id"]      ?? "");
$access_token = trim($data["access_token"] ?? "");
$message      = trim($data["message"]      ?? "");
$image_url    = trim($data["image_url"]    ?? "");
$story_image_url = trim($data["story_image_url"] ?? "");
$scheduled_at = trim($data["scheduled_at"] ?? "");
$post_type    = trim($data["post_type"]    ?? "");
$cover_url    = trim($data["cover_url"]    ?? "");

if (!$platform || !$page_id || !$access_token || !$message) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields: platform, page_id, access_token, message"]);
    exit;
}

if (!in_array($platform, ["facebook", "instagram", "linkedin", "tiktok"])) {
    http_response_code(400);
    echo json_encode(["error" => "Unsupported platform. Supported: facebook, instagram, linkedin, tiktok"]);
    exit;
}

if ($platform === "linkedin") {
    [$http_code, $response] = linkedin_publish($page_id, $access_token, $message, $image_url);
} elseif ($platform === "tiktok") {
    // TikTok publishes video only — image_url is expected to point at a
    // video file here (the frontend enforces this, see IntegrationWizard's
    // TikTok connect step / the Ready Content video-attachment check).
    if (!$image_url) {
        http_response_code(400);
        echo json_encode(["error" => "TikTok requires a video file attached to this post — no image/video URL was provided."]);
        exit;
    }
    [$http_code, $response] = tiktok_publish_video($access_token, $image_url, $message);
} else {
    [$http_code, $response] = meta_publish($platform, $page_id, $access_token, $message, $image_url, $scheduled_at ?: null, $story_image_url ?: null, $post_type ?: null, $cover_url ?: null);
}
http_response_code($http_code);
echo json_encode($response);
