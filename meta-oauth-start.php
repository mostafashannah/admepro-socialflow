<?php
// ================================================================
// SocialFlow — Step 1 of the Instagram "Connect" flow, using Meta's
// "Instagram API with Instagram login" product (instagram.com OAuth,
// not the classic Facebook Login dialog). No Facebook Page required —
// the user logs in directly with their Instagram professional account.
// Opened in a popup from Settings → Integrations.
// ================================================================

require_once __DIR__ . '/config.php';

if (!defined('INSTAGRAM_APP_ID') || !defined('INSTAGRAM_APP_SECRET')) {
    http_response_code(500);
    echo 'INSTAGRAM_APP_ID / INSTAGRAM_APP_SECRET not configured in config.php';
    exit;
}

// Never let the browser cache this redirect — the Location carries the scope
// list and a one-time state nonce, both of which must be fresh on every click.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/meta-oauth-callback.php';

$state = bin2hex(random_bytes(16));
setcookie('ig_oauth_state', $state, [
    'expires'  => time() + 600,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$scopes = implode(',', [
    'instagram_business_basic',
    'instagram_business_manage_comments',
    'instagram_business_manage_messages',
    'instagram_business_content_publish',
]);

$params = [
    'client_id'     => INSTAGRAM_APP_ID,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => $scopes,
    'state'         => $state,
    'force_reauth'  => 'true',
];

header('Location: https://www.instagram.com/oauth/authorize?' . http_build_query($params));
exit;
