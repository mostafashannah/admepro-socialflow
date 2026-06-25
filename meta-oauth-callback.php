<?php
// ================================================================
// SocialFlow — Step 2 of Facebook/Instagram OAuth "Connect" flow.
// Facebook redirects here with ?code=...&state=... after the user
// approves the login dialog from meta-oauth-start.php. We exchange
// the code for a long-lived token, list the user's Pages (+ any
// linked Instagram Business account per page), then postMessage the
// result back to the opener window (Settings → Integrations) and
// close the popup.
// ================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/meta-lib.php';

function meta_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return [null, "cURL error: {$err}"];
    $data = json_decode($res, true);
    if (isset($data['error'])) return [null, $data['error']['message'] ?? 'Unknown Graph API error'];
    return [$data, null];
}

function finish($ok, $payload) {
    header('Content-Type: text/html; charset=UTF-8');
    $json = json_encode(array_merge(['type' => 'meta_oauth_result', 'ok' => $ok], $payload));
    echo "<!DOCTYPE html><html><body><script>
        if (window.opener) { window.opener.postMessage(" . $json . ", window.location.origin); }
        window.close();
    </script><p>You can close this window.</p></body></html>";
    exit;
}

$state = $_GET['state'] ?? '';
$cookieState = $_COOKIE['meta_oauth_state'] ?? '';
if (!$state || !$cookieState || !hash_equals($cookieState, $state)) {
    finish(false, ['error' => 'Invalid or expired login attempt. Please try connecting again.']);
}
setcookie('meta_oauth_state', '', ['expires' => time() - 3600, 'path' => '/']);

if (!empty($_GET['error'])) {
    finish(false, ['error' => $_GET['error_description'] ?? $_GET['error']]);
}

$code = $_GET['code'] ?? '';
if (!$code) {
    finish(false, ['error' => 'No authorization code received from Facebook.']);
}

$v = META_GRAPH_VERSION;
$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/meta-oauth-callback.php';

// Step 1: exchange code for a short-lived user access token
[$tokenResp, $err] = meta_get("https://graph.facebook.com/{$v}/oauth/access_token?" . http_build_query([
    'client_id'     => META_APP_ID,
    'client_secret' => META_APP_SECRET,
    'redirect_uri'  => $redirectUri,
    'code'          => $code,
]));
if ($err || empty($tokenResp['access_token'])) {
    finish(false, ['error' => 'Token exchange failed: ' . ($err ?: 'no access_token in response')]);
}
$shortToken = $tokenResp['access_token'];

// Step 2: exchange for a long-lived user access token (~60 days)
[$longResp, $err] = meta_get("https://graph.facebook.com/{$v}/oauth/access_token?" . http_build_query([
    'grant_type'        => 'fb_exchange_token',
    'client_id'          => META_APP_ID,
    'client_secret'      => META_APP_SECRET,
    'fb_exchange_token'  => $shortToken,
]));
$userToken = $longResp['access_token'] ?? $shortToken;

// Step 3: list the Pages this user manages
[$pagesResp, $err] = meta_get("https://graph.facebook.com/{$v}/me/accounts?" . http_build_query([
    'fields'       => 'id,name,access_token',
    'access_token' => $userToken,
]));
if ($err) {
    finish(false, ['error' => 'Could not list Facebook Pages: ' . $err]);
}

$pages = [];
foreach (($pagesResp['data'] ?? []) as $p) {
    $entry = ['id' => $p['id'], 'name' => $p['name'], 'access_token' => $p['access_token']];

    // Check for a linked Instagram Business account on this Page
    [$igResp, ] = meta_get("https://graph.facebook.com/{$v}/{$p['id']}?" . http_build_query([
        'fields'       => 'instagram_business_account{id,username}',
        'access_token' => $p['access_token'],
    ]));
    if (!empty($igResp['instagram_business_account']['id'])) {
        $entry['instagram'] = [
            'id'       => $igResp['instagram_business_account']['id'],
            'username' => $igResp['instagram_business_account']['username'] ?? '',
        ];
    }
    $pages[] = $entry;
}

finish(true, ['pages' => $pages]);
