<?php
// ================================================================
// SocialFlow — single-shot reel status check + publish.
// Called repeatedly by the frontend after social-publish.php returns
// {status:"processing", container_id, ig_user_id} for a Reel post.
// Each call checks the container status once and, when FINISHED,
// triggers the final media_publish — keeping each HTTP request fast
// so nginx never hits its gateway timeout.
// ================================================================
require_once 'config.php';
require_once 'meta-lib.php';
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
$container_id = trim($data["container_id"]  ?? "");
$ig_user_id   = trim($data["ig_user_id"]    ?? "");
$access_token = trim($data["access_token"]  ?? "");

if (!$container_id || !$ig_user_id || !$access_token) {
    http_response_code(400);
    echo json_encode(["error" => "Missing container_id, ig_user_id or access_token"]);
    exit;
}

[$http_code, $response] = ig_poll_and_publish(META_GRAPH_VERSION, $ig_user_id, $access_token, $container_id);
http_response_code($http_code);
echo json_encode($response);
