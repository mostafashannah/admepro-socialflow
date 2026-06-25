<?php
// ================================================================
// SocialFlow — Step 1 of Facebook/Instagram OAuth "Connect" flow.
// Opened in a popup from Settings → Integrations. Redirects the user
// to Facebook's login dialog, then Facebook redirects back to
// meta-oauth-callback.php with a ?code=... we exchange for a token.
// ================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/meta-lib.php';

if (!defined('META_APP_ID') || !defined('META_APP_SECRET')) {
    http_response_code(500);
    echo 'META_APP_ID / META_APP_SECRET not configured in config.php';
    exit;
}

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/meta-oauth-callback.php';

$state = bin2hex(random_bytes(16));
setcookie('meta_oauth_state', $state, [
    'expires'  => time() + 600,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$scopes = implode(',', [
    'pages_show_list',
    'pages_read_engagement',
    'pages_manage_posts',
    'pages_manage_metadata',
    'read_insights',
    'instagram_basic',
    'instagram_content_publish',
    'instagram_manage_comments',
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
