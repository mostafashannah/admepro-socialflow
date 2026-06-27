<?php
// ================================================================
// SocialFlow — Step 1 of the Facebook Pages "Connect" flow, using
// Facebook Login for Business on the main Meta app (the same app
// already used for WhatsApp / the Messenger+Instagram inbox webhook).
// Opened in a popup from Settings → Integrations.
// ================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/meta-lib.php';

if (!defined('META_APP_ID') || !defined('META_APP_SECRET')) {
    http_response_code(500);
    echo 'META_APP_ID / META_APP_SECRET not configured in config.php';
    exit;
}

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/fb-oauth-callback.php';

$state = bin2hex(random_bytes(16));
setcookie('fb_oauth_state', $state, [
    'expires'  => time() + 600,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Classic scope-list OAuth dialog. We deliberately do NOT use the
// "Facebook Login for Business" config_id flow: it failed 100% of the time with a
// generic "Sorry, something went wrong" error for this app across every variation
// tested (every configuration, permission set, platform registration, and login
// variation), while the classic dialog below works reliably. "pages_messaging" was
// verified to be accepted by the classic dialog directly — the earlier belief that it
// required Login-for-Business / a config_id was incorrect. META_FB_LOGIN_CONFIG_ID is
// intentionally ignored now and can be removed from config.php.
$scopes = implode(',', [
    'pages_show_list',
    'pages_read_engagement',
    'pages_manage_posts',
    'pages_manage_metadata',
    'pages_manage_engagement',
    'pages_messaging',
    'read_insights',
    'business_management',
]);
$params = [
    'client_id'     => META_APP_ID,
    'redirect_uri'  => $redirectUri,
    'state'         => $state,
    'scope'         => $scopes,
    'response_type' => 'code',
];

header('Location: https://www.facebook.com/' . META_GRAPH_VERSION . '/dialog/oauth?' . http_build_query($params));
exit;
