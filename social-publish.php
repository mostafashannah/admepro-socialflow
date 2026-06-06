<?php
require_once 'config.php';
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
$scheduled_at = trim($data["scheduled_at"] ?? ""); // ISO-8601, optional

if (!$platform || !$page_id || !$access_token || !$message) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields: platform, page_id, access_token, message"]);
    exit;
}

if (!in_array($platform, ["facebook", "instagram"])) {
    http_response_code(400);
    echo json_encode(["error" => "Unsupported platform. Supported: facebook, instagram"]);
    exit;
}

$graph_version = "v19.0";

if ($platform === "facebook") {
    // ── Facebook Page post ──────────────────────────────────────────
    if ($image_url) {
        // Photo post
        $endpoint   = "https://graph.facebook.com/{$graph_version}/{$page_id}/photos";
        $post_data  = [
            "url"          => $image_url,
            "caption"      => $message,
            "access_token" => $access_token,
        ];
    } else {
        // Text / link post
        $endpoint  = "https://graph.facebook.com/{$graph_version}/{$page_id}/feed";
        $post_data = [
            "message"      => $message,
            "access_token" => $access_token,
        ];
    }

    if ($scheduled_at) {
        $ts = strtotime($scheduled_at);
        if ($ts && $ts > time()) {
            $post_data["published"]       = "false";
            $post_data["scheduled_publish_time"] = $ts;
        }
    }

} elseif ($platform === "instagram") {
    // ── Instagram Business: two-step (create container → publish) ───

    if (!$image_url) {
        http_response_code(400);
        echo json_encode(["error" => "Instagram requires image_url"]);
        exit;
    }

    // Step 1 — create media container
    $container_ep = "https://graph.facebook.com/{$graph_version}/{$page_id}/media";
    $container_data = [
        "image_url"    => $image_url,
        "caption"      => $message,
        "access_token" => $access_token,
    ];

    $ch = curl_init($container_ep);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($container_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $container_resp = json_decode(curl_exec($ch), true);
    $container_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($container_code !== 200 || empty($container_resp["id"])) {
        http_response_code($container_code ?: 502);
        echo json_encode(["error" => "Failed to create media container", "detail" => $container_resp]);
        exit;
    }

    // Step 2 — publish container
    $endpoint  = "https://graph.facebook.com/{$graph_version}/{$page_id}/media_publish";
    $post_data = [
        "creation_id"  => $container_resp["id"],
        "access_token" => $access_token,
    ];
}

// ── Execute the final POST ────────────────────────────────────────
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($post_data),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
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
