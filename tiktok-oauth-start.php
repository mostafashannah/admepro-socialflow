<?php
// ================================================================
// SocialFlow — Step 1 of the TikTok "Connect" flow.
// Opened in a popup from Settings → Integrations.
//
// Unlike Meta/LinkedIn, TikTok's v2 OAuth REQUIRES PKCE (code_verifier /
// code_challenge) even for a confidential server-side app — a plain
// authorization_code exchange without it is rejected.
// ================================================================

require_once __DIR__ . '/config.php';

if (!defined('TIKTOK_CLIENT_KEY') || !defined('TIKTOK_CLIENT_SECRET')) {
    http_response_code(500);
    echo 'TIKTOK_CLIENT_KEY / TIKTOK_CLIENT_SECRET not configured in config.php';
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/tiktok-oauth-callback.php';

$state = bin2hex(random_bytes(16));
// PKCE: verifier is a random string; challenge is base64url(sha256(verifier)).
$codeVerifier = bin2hex(random_bytes(32));
$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

$cookieOpts = ['expires' => time() + 600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
setcookie('tiktok_oauth_state', $state, $cookieOpts);
setcookie('tiktok_oauth_verifier', $codeVerifier, $cookieOpts);

// user.info.basic: display name for the connected-account picker.
// video.publish: required to actually post via the Content Posting API.
// video.list isn't available on this app's product tier (Display/Research
// API access), so per-video insights aren't requested — publishing still
// works fine without it; post-insights-cron.php's TikTok branch just finds
// nothing to fetch and skips those posts, same as any unmatched post.
$scopes = implode(',', ['user.info.basic', 'video.publish']);
$params = [
    'client_key'            => TIKTOK_CLIENT_KEY,
    'response_type'         => 'code',
    'scope'                 => $scopes,
    'redirect_uri'          => $redirectUri,
    'state'                 => $state,
    'code_challenge'        => $codeChallenge,
    'code_challenge_method' => 'S256',
];

header('Location: https://www.tiktok.com/v2/auth/authorize/?' . http_build_query($params));
exit;
