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

// "pages_messaging" (and several other Page-asset permissions) can only be granted
// through "Facebook Login for Business" — a config_id created in the App Dashboard
// under Use Cases → Facebook Login for Business, which bundles the permission set.
// The classic scope-list dialog rejects pages_messaging outright ("Invalid Scopes").
// Falls back to the old scope-based dialog (no messaging) if no config_id is set,
// so existing setups that only need posting/insights keep working unmodified.
if (defined('META_FB_LOGIN_CONFIG_ID') && META_FB_LOGIN_CONFIG_ID) {
    $params = [
        'client_id'     => META_APP_ID,
        'redirect_uri'  => $redirectUri,
        'state'         => $state,
        'response_type' => 'code',
        'config_id'     => META_FB_LOGIN_CONFIG_ID,
    ];
} else {
    $scopes = implode(',', [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'pages_manage_metadata',
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
}

header('Location: https://www.facebook.com/' . META_GRAPH_VERSION . '/dialog/oauth?' . http_build_query($params));
exit;
