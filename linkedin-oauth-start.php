<?php
// ================================================================
// SocialFlow — Step 1 of the LinkedIn "Connect" flow.
// Opened in a popup from Settings → Integrations.
// ================================================================

require_once __DIR__ . '/config.php';

if (!defined('LINKEDIN_CLIENT_ID') || !defined('LINKEDIN_CLIENT_SECRET')) {
    http_response_code(500);
    echo 'LINKEDIN_CLIENT_ID / LINKEDIN_CLIENT_SECRET not configured in config.php';
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$redirectUri = $origin . '/linkedin-oauth-callback.php';

$state = bin2hex(random_bytes(16));
setcookie('linkedin_oauth_state', $state, [
    'expires'  => time() + 600,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// "openid profile" (Sign In with LinkedIn using OpenID Connect) gets us the
// member's URN via /v2/userinfo; "w_member_social" (Share on LinkedIn) lets
// us post as that member.
$scopes = implode(' ', ['openid', 'profile', 'w_member_social']);
$params = [
    'response_type' => 'code',
    'client_id'      => LINKEDIN_CLIENT_ID,
    'redirect_uri'   => $redirectUri,
    'state'          => $state,
    'scope'          => $scopes,
];

header('Location: https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params));
exit;
