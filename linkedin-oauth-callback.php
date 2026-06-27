<?php
// ================================================================
// SocialFlow — Step 2 of the LinkedIn "Connect" flow.
// LinkedIn redirects here with ?code=...&state=... after the user
// approves the login dialog from linkedin-oauth-start.php. We exchange
// the code for an access token, fetch the member's profile (for their
// URN + display name), then postMessage the result back to the opener
// window (Settings → Integrations) and close the popup.
// ================================================================

require_once __DIR__ . '/config.php';

function li_get($url, $access_token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return [null, "cURL error: {$err}"];
    $data = json_decode($res, true);
    if (isset($data['error'])) return [null, $data['error_description'] ?? $data['error']];
    return [$data, null];
}

function finish($ok, $payload) {
    error_log('linkedin-oauth-callback finish: ok=' . ($ok ? '1' : '0') . ' payload=' . json_encode($payload));
    header('Content-Type: text/html; charset=UTF-8');
    $json = json_encode(array_merge(['type' => 'linkedin_oauth_result', 'ok' => $ok], $payload));
    echo "<!DOCTYPE html><html><body><script>
        if (window.opener) { window.opener.postMessage(" . $json . ", window.location.origin); }
        window.close();
    </script><p>You can close this window.</p></body></html>";
    exit;
}

$state = $_GET['state'] ?? '';
$cookieState = $_COOKIE['linkedin_oauth_state'] ?? '';
if (!$state || !$cookieState || !hash_equals($cookieState, $state)) {
    finish(false, ['error' => 'Invalid or expired login attempt. Please try connecting again.']);
}
setcookie('linkedin_oauth_state', '', ['expires' => time() - 3600, 'path' => '/']);

if (!empty($_GET['error'])) {
    finish(false, ['error' => $_GET['error_description'] ?? $_GET['error']]);
}

$code = $_GET['code'] ?? '';
if (!$code) {
    finish(false, ['error' => 'No authorization code received from LinkedIn.']);
}

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/linkedin-oauth-callback.php';

// Step 1: exchange code for an access token (~60 days, no refresh w/o
// separate refresh-token product approval).
$ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'client_id'     => LINKEDIN_CLIENT_ID,
        'client_secret' => LINKEDIN_CLIENT_SECRET,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 20,
]);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
$tokenResp = json_decode($res, true);

if ($err || empty($tokenResp['access_token'])) {
    finish(false, ['error' => 'Token exchange failed: ' . ($err ?: ($tokenResp['error_description'] ?? 'no access_token in response'))]);
}
$accessToken = $tokenResp['access_token'];

// Step 2: fetch the member's profile (OpenID Connect userinfo endpoint).
[$profile, $err] = li_get('https://api.linkedin.com/v2/userinfo', $accessToken);
if ($err || empty($profile['sub'])) {
    finish(false, ['error' => 'Could not fetch LinkedIn profile: ' . ($err ?: 'no member id returned')]);
}

$accounts = [[
    'id'           => 'urn:li:person:' . $profile['sub'],
    'name'         => $profile['name'] ?? ($profile['given_name'] ?? 'LinkedIn Member'),
    'access_token' => $accessToken,
]];

finish(true, ['accounts' => $accounts]);
