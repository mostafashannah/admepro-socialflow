<?php
// ================================================================
// SocialFlow — Step 2 of the Instagram "Connect" flow (Instagram API
// with Instagram login). Instagram redirects here with ?code=...&
// state=... after the user approves the login dialog from
// meta-oauth-start.php. We exchange the code for a short-lived token,
// exchange that for a long-lived token (~60 days), fetch the
// connected account's id/username, then postMessage the result back
// to the opener window (Settings → Integrations) and close the popup.
// ================================================================

require_once __DIR__ . '/config.php';

function ig_post($url, $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return [null, "cURL error: {$err}"];
    $data = json_decode($res, true);
    if (isset($data['error_message'])) return [null, $data['error_message']];
    if (isset($data['error'])) return [null, is_array($data['error']) ? ($data['error']['message'] ?? 'Unknown error') : $data['error']];
    return [$data, null];
}

function ig_get($url) {
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
    if (isset($data['error'])) return [null, is_array($data['error']) ? ($data['error']['message'] ?? 'Unknown error') : $data['error']];
    return [$data, null];
}

function finish($ok, $payload) {
    error_log('meta-oauth-callback finish: ok=' . ($ok ? '1' : '0') . ' payload=' . json_encode($payload));
    header('Content-Type: text/html; charset=UTF-8');
    $json = json_encode(array_merge(['type' => 'meta_oauth_result', 'ok' => $ok], $payload));
    echo "<!DOCTYPE html><html><body><script>
        if (window.opener) { window.opener.postMessage(" . $json . ", window.location.origin); }
        window.close();
    </script><p>You can close this window.</p></body></html>";
    exit;
}

$state = $_GET['state'] ?? '';
$cookieState = $_COOKIE['ig_oauth_state'] ?? '';
if (!$state || !$cookieState || !hash_equals($cookieState, $state)) {
    finish(false, ['error' => 'Invalid or expired login attempt. Please try connecting again.']);
}
setcookie('ig_oauth_state', '', ['expires' => time() - 3600, 'path' => '/']);

if (!empty($_GET['error'])) {
    finish(false, ['error' => $_GET['error_description'] ?? $_GET['error']]);
}

$code = $_GET['code'] ?? '';
if (!$code) {
    finish(false, ['error' => 'No authorization code received from Instagram.']);
}
// Instagram sometimes appends "#_" to the code in the redirect.
$code = rtrim($code, '#_');

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/meta-oauth-callback.php';

// Step 1: exchange code for a short-lived access token
[$tokenResp, $err] = ig_post('https://api.instagram.com/oauth/access_token', [
    'client_id'     => INSTAGRAM_APP_ID,
    'client_secret' => INSTAGRAM_APP_SECRET,
    'grant_type'    => 'authorization_code',
    'redirect_uri'  => $redirectUri,
    'code'          => $code,
]);
if ($err || empty($tokenResp['access_token'])) {
    finish(false, ['error' => 'Token exchange failed: ' . ($err ?: 'no access_token in response')]);
}
$shortToken = $tokenResp['access_token'];
$igUserId   = $tokenResp['user_id'] ?? '';

// Step 2: exchange for a long-lived access token (~60 days)
[$longResp, $err] = ig_get('https://graph.instagram.com/access_token?' . http_build_query([
    'grant_type'    => 'ig_exchange_token',
    'client_secret' => INSTAGRAM_APP_SECRET,
    'access_token'  => $shortToken,
]));
$longToken = $longResp['access_token'] ?? $shortToken;

// Step 3: fetch the connected account's id/username
[$meResp, $err] = ig_get('https://graph.instagram.com/me?' . http_build_query([
    'fields'       => 'id,username,account_type',
    'access_token' => $longToken,
]));
if ($err) {
    finish(false, ['error' => 'Could not fetch Instagram account info: ' . $err]);
}

$accountId = $meResp['id'] ?? $igUserId;

// Step 4: subscribe this account to webhook delivery. App-level webhook config
// (Callback URL + Verify Token + field subscription) only registers the app with
// Meta — each connected account must separately opt in to receive events, or no
// webhook POSTs will ever arrive for it.
if ($accountId) {
    ig_post("https://graph.instagram.com/v21.0/{$accountId}/subscribed_apps", [
        'subscribed_fields' => 'messages',
        'access_token'      => $longToken,
    ]);
}

finish(true, ['accounts' => [[
    'id'           => $accountId,
    'username'     => $meResp['username'] ?? '',
    'account_type' => $meResp['account_type'] ?? '',
    'access_token' => $longToken,
]]]);
