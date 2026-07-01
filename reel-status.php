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
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST")    { http_response_code(405); echo json_encode(["error"=>"Method not allowed"]); exit; }

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
