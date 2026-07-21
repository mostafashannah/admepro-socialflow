<?php
// ================================================================
// SocialFlow — Step 2 of the TikTok "Connect" flow.
// TikTok redirects here with ?code=...&state=... after the user approves
// the login dialog from tiktok-oauth-start.php. Exchanges the code (plus
// the PKCE verifier from that step's cookie) for an access/refresh token
// pair, fetches the account's display name, then postMessages the result
// back to the opener window (Settings → Integrations) and closes the popup.
// ================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tiktok-lib.php';

function finish($ok, $payload) {
    error_log('tiktok-oauth-callback finish: ok=' . ($ok ? '1' : '0') . ' payload=' . json_encode($payload));
    header('Content-Type: text/html; charset=UTF-8');
    $json = json_encode(array_merge(['type' => 'tiktok_oauth_result', 'ok' => $ok], $payload));
    echo "<!DOCTYPE html><html><body><script>
        if (window.opener) { window.opener.postMessage(" . $json . ", window.location.origin); }
        window.close();
    </script><p>You can close this window.</p></body></html>";
    exit;
}

$state = $_GET['state'] ?? '';
$cookieState = $_COOKIE['tiktok_oauth_state'] ?? '';
$codeVerifier = $_COOKIE['tiktok_oauth_verifier'] ?? '';
if (!$state || !$cookieState || !hash_equals($cookieState, $state) || !$codeVerifier) {
    finish(false, ['error' => 'Invalid or expired login attempt. Please try connecting again.']);
}
setcookie('tiktok_oauth_state', '', ['expires' => time() - 3600, 'path' => '/']);
setcookie('tiktok_oauth_verifier', '', ['expires' => time() - 3600, 'path' => '/']);

if (!empty($_GET['error'])) {
    finish(false, ['error' => $_GET['error_description'] ?? $_GET['error']]);
}

$code = $_GET['code'] ?? '';
if (!$code) {
    finish(false, ['error' => 'No authorization code received from TikTok.']);
}

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/tiktok-oauth-callback.php';

// Step 1: exchange code (+ PKCE verifier) for access_token/refresh_token.
$ch = curl_init(TIKTOK_API_BASE . '/oauth/token/');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_key'     => TIKTOK_CLIENT_KEY,
        'client_secret'  => TIKTOK_CLIENT_SECRET,
        'code'           => $code,
        'grant_type'     => 'authorization_code',
        'redirect_uri'   => $redirectUri,
        'code_verifier'  => $codeVerifier,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
$tokenResp = json_decode($res, true);

if ($err || empty($tokenResp['access_token'])) {
    finish(false, ['error' => 'Token exchange failed: ' . ($err ?: ($tokenResp['error_description'] ?? $tokenResp['error']['message'] ?? 'no access_token in response'))]);
}
$accessToken  = $tokenResp['access_token'];
$refreshToken = $tokenResp['refresh_token'] ?? '';
$expiresAt    = date('Y-m-d H:i:s', time() + (int)($tokenResp['expires_in'] ?? 86400) - 300);
$openId       = $tokenResp['open_id'] ?? '';

// Step 2: fetch display name for the account picker.
[$code2, $profile] = tiktok_curl(TIKTOK_API_BASE . '/user/info/?fields=' . urlencode('open_id,display_name'), 'GET', null, $accessToken);
$displayName = $profile['data']['user']['display_name'] ?? 'TikTok Account';
$resolvedOpenId = $profile['data']['user']['open_id'] ?? $openId;

if (!$resolvedOpenId) {
    finish(false, ['error' => 'Could not fetch TikTok profile: no account id returned']);
}

$accounts = [[
    'id'            => $resolvedOpenId,
    'name'          => $displayName,
    'access_token'  => $accessToken,
    'refresh_token' => $refreshToken,
    'expires_at'    => $expiresAt,
]];

finish(true, ['accounts' => $accounts]);
