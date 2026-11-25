<?php
require_once 'config.php';
require_once __DIR__ . '/auth-lib.php';
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
if (!authTeamOk(false)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

// TikTok Content Sharing Guidelines require the posting UI to be built
// fresh from this call every time (creator nickname, their real
// privacy_level_options, which interactions they've allowed, max post
// duration) — never from a hardcoded assumption in the frontend.
if (($data["action"] ?? "") === "creator_info") {
    $accessToken = trim($data["access_token"] ?? "");
    if (!$accessToken) {
        http_response_code(400);
        echo json_encode(["error" => "Missing access_token"]);
        exit;
    }
    [$code, $resp] = tiktok_creator_info($accessToken);
    http_response_code($code);
    echo json_encode($resp);
    exit;
}

$platform     = strtolower(trim($data["platform"]     ?? ""));
$page_id      = trim($data["page_id"]      ?? "");
$access_token = trim($data["access_token"] ?? "");
$message      = trim($data["message"]      ?? "");
$image_url    = trim($data["image_url"]    ?? "");
// Carousel — every slide, in the exact order arranged in the Design phase.
$image_urls   = array_values(array_filter(array_map('trim', $data["image_urls"] ?? []), fn($u) => $u !== ''));
$story_image_url = trim($data["story_image_url"] ?? "");
$scheduled_at = trim($data["scheduled_at"] ?? "");
$post_type    = trim($data["post_type"]    ?? "");
$cover_url    = trim($data["cover_url"]    ?? "");

if (!$platform || !$page_id || !$access_token || !$message) {
    // Naming exactly which field(s) are empty (instead of always listing all
    // four) — a post with a real, connected integration but just no caption
    // yet used to surface "platform, page_id, access_token, message" all
    // missing, which reads like the whole integration is broken when it's
    // really just an empty caption.
    $missing = [];
    if (!$platform) $missing[] = "platform";
    if (!$page_id) $missing[] = "page_id";
    if (!$access_token) $missing[] = "access_token";
    if (!$message) $missing[] = "message (caption)";
    http_response_code(400);
    echo json_encode(["error" => "Missing required field(s): " . implode(", ", $missing)]);
    exit;
}

if (!in_array($platform, ["facebook", "instagram", "linkedin", "tiktok", "twitter", "website"])) {
    http_response_code(400);
    echo json_encode(["error" => "Unsupported platform. Supported: facebook, instagram, linkedin, tiktok, twitter, website"]);
    exit;
}

if ($platform === "website") {
    // An article on the client's own website: the integration's saved connection is read here (the browser never sends the GitHub token).
    require_once __DIR__ . '/website-publish-lib.php';
    $wpdo = authPdo(); $wpost = null;
    $wid = preg_replace('/[^A-Za-z0-9-]/', '', (string)($data["post_id"] ?? ""));
    if ($wid !== "") { $q = $wpdo->prepare("SELECT * FROM posts WHERE id = :id LIMIT 1"); $q->execute([':id' => $wid]); $wpost = $q->fetch(PDO::FETCH_ASSOC); }
    $wi = null;
    if ($wpost) {
        if (!empty($wpost['client_id'])) { $q = $wpdo->prepare("SELECT credentials FROM integrations WHERE app_key = 'website' AND status = 'active' AND client_id = :c LIMIT 1"); $q->execute([':c' => $wpost['client_id']]); $wi = $q->fetch(PDO::FETCH_ASSOC); }
    }
    if (!$wpost || !$wi) { $http_code = 400; $response = ['error' => 'No active website connection for this client (Client → Settings → Integrations → Website), or the task id is missing.']; }
    else [$http_code, $response] = website_publish_article($wpdo, json_decode((string)$wi['credentials'], true) ?: [], $wpost);
} elseif ($platform === "twitter") {
    // X is posted through Zernio only (credentials: page_id = Zernio account id, access_token = "zernio").
    require_once __DIR__ . '/zernio-lib.php';
    if ($access_token !== ZERNIO_TOKEN_MARKER) { $http_code = 400; $response = ['error' => 'X must be connected through Zernio.']; }
    elseif (mb_strlen($message) > 280) { $http_code = 422; $response = ['error' => 'X posts are limited to 280 characters (this one is ' . mb_strlen($message) . ').']; }
    else [$http_code, $response] = zernio_publish('twitter', $page_id, $message, count($image_urls) > 1 ? array_slice($image_urls, 0, 4) : ($image_url ? [$image_url] : []));   // a carousel: one post, up to 4 pictures (X's limit), in order
} elseif ($platform === "linkedin") {
    [$http_code, $response] = linkedin_publish($page_id, $access_token, $message, $image_url, count($image_urls) > 1 ? $image_urls : []);
} elseif ($platform === "tiktok") {
    // TikTok publishes video only — image_url is expected to point at a
    // video file here (the frontend enforces this, see IntegrationWizard's
    // TikTok connect step / the Ready Content video-attachment check).
    if (!$image_url) {
        http_response_code(400);
        echo json_encode(["error" => "TikTok requires a video file attached to this post — no image/video URL was provided."]);
        exit;
    }
    $tiktokOptions = [
        'privacy_level'       => trim($data['privacy_level'] ?? ''),
        'allow_comment'       => !empty($data['allow_comment']),
        'allow_duet'          => !empty($data['allow_duet']),
        'allow_stitch'        => !empty($data['allow_stitch']),
        'disclose_commercial' => !empty($data['disclose_commercial']),
        'your_brand'          => !empty($data['your_brand']),
        'branded_content'     => !empty($data['branded_content']),
    ];
    [$http_code, $response] = tiktok_publish_video($access_token, $image_url, $message, $tiktokOptions);
} else {
    [$http_code, $response] = meta_publish($platform, $page_id, $access_token, $message, $image_url, $scheduled_at ?: null, $story_image_url ?: null, $post_type ?: null, $cover_url ?: null, $image_urls ?: null);
}
// Record this manual publish on the matching integration (status dot, error badge, activity log), best effort.
try {
    $okRun = $http_code >= 200 && $http_code < 300;
    $dbp = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $f = $dbp->prepare("SELECT id, name FROM integrations WHERE app_key = ? AND status IN ('active','error') AND credentials LIKE ? LIMIT 1");
    $f->execute([$platform, '%' . str_replace(['%', '_'], ['\\%', '\\_'], $page_id) . '%']);
    if ($ig = $f->fetch(PDO::FETCH_ASSOC)) {
        $msg = $okRun ? 'Published manually' : ('Manual publish failed: ' . mb_substr(is_string($response['error'] ?? null) ? $response['error'] : json_encode($response['error'] ?? $response), 0, 200));
        $dbp->prepare("UPDATE integrations SET last_run_at = NOW(), last_run_status = ?, last_run_message = ?, run_count = run_count + 1, error_count = IF(?, 0, error_count + 1) WHERE id = ?")
            ->execute([$okRun ? 'success' : 'error', $msg, $okRun ? 1 : 0, $ig['id']]);
        $dbp->prepare("INSERT INTO integration_logs (id, integration_id, integration_name, status, error, payload_summary, triggered_by) VALUES (UUID(), ?, ?, ?, ?, ?, 'manual-publish')")
            ->execute([$ig['id'], $ig['name'], $okRun ? 'success' : 'error', $okRun ? null : json_encode($response), 'Manual publish (' . $platform . ')']);
    }
} catch (Throwable $e) { error_log('[social-publish] run log: ' . $e->getMessage()); }
http_response_code($http_code);
echo json_encode($response);
