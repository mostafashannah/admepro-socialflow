<?php
// SocialFlow — Configuration template
// Copy to config.php and fill in your real keys

define('ANTHROPIC_API_KEY', 'sk-ant-api03-YOUR_KEY_HERE');
define('RESEND_API_KEY',    're_YOUR_RESEND_KEY');
define('B44_API_KEY_VAL',   'your_base44_api_key');
define('B44_APP_ID_VAL',    'your_base44_app_id');

// WhatsApp Business Cloud API (Meta)
// Get from: https://developers.facebook.com → your app → WhatsApp → API Setup
define('WA_PHONE_ID',      'your_whatsapp_phone_number_id');
define('WA_ACCESS_TOKEN',  'your_whatsapp_access_token');

// Inbound WhatsApp (wa-webhook.php) — lets team/clients message "Pro" directly.
// WA_VERIFY_TOKEN: any string you choose; enter the SAME value when subscribing
//   the webhook in Meta App Dashboard → WhatsApp → Configuration → Verify Token.
// WA_APP_SECRET: Meta App Dashboard → App Settings → Basic → App Secret. Used to
//   verify the X-Hub-Signature-256 header on incoming webhook POSTs.
define('WA_VERIFY_TOKEN', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');
define('WA_APP_SECRET',   'your_meta_app_secret');

// Facebook/Instagram auto-publish (auto-publish.php cron) — kill switch.
// Leave false until you've connected at least one active Facebook/Instagram
// integration in Settings → Integrations with a real Page Access Token.
define('AUTO_PUBLISH_ENABLED', false);

// Timezone used when comparing posts' scheduled_date/scheduled_time against
// "now" in auto-publish.php. Use a PHP timezone identifier, e.g. 'Africa/Cairo'.
define('APP_TIMEZONE', 'UTC');

// Meta App (Messenger/Instagram customer inbox webhook). App ID + Secret:
// developers.facebook.com → your app → Settings → Basic.
// Verify Token: any string you choose, entered again when subscribing the webhook
define('META_APP_ID',                'your_meta_app_id');
define('META_APP_SECRET',            'your_meta_app_secret');
define('META_WEBHOOK_VERIFY_TOKEN',  'choose_any_random_string');

// Required for the "Connect with Facebook" button to grant Messenger send/receive
// permission (pages_messaging). Create one at developers.facebook.com → your app →
// Use Cases → Facebook Login for Business → Configurations → Create configuration:
// asset type "Page", permissions pages_show_list + pages_read_engagement +
// pages_manage_posts + pages_manage_metadata + pages_messaging + read_insights +
// business_management. Copy the resulting Configuration ID here. Leave undefined to
// fall back to the old scope-list dialog (Page posting/insights only, no messaging).
define('META_FB_LOGIN_CONFIG_ID',    '');

// Instagram API with Instagram login — the "Connect with Instagram" button
// in Settings → Integrations (meta-oauth-start.php / meta-oauth-callback.php).
// This is a SEPARATE product/credential from the Meta App above — get it from
// developers.facebook.com → your app → Instagram API → API setup with
// Instagram login. Also add this exact URL as a valid OAuth redirect URI there:
// https://yourdomain.com/meta-oauth-callback.php
define('INSTAGRAM_APP_ID',           'your_instagram_app_id');
define('INSTAGRAM_APP_SECRET',       'your_instagram_app_secret');

// LinkedIn (linkedin-oauth-start.php / linkedin-oauth-callback.php) — the
// "Connect with LinkedIn" button in Settings → Integrations. Create an app at
// https://www.linkedin.com/developers/apps, request the "Share on LinkedIn" AND
// "Sign In with LinkedIn using OpenID Connect" products, then add this exact
// redirect URL under the Auth tab: https://yourdomain.com/linkedin-oauth-callback.php
// Note: LinkedIn's public API only supports posting as the connecting member
// (or a Company Page if you're separately approved for the Marketing API) —
// it does not support reading or replying to DMs/comments.
define('LINKEDIN_CLIENT_ID',     'your_linkedin_client_id');
define('LINKEDIN_CLIENT_SECRET', 'your_linkedin_client_secret');

// --- Self-hosted MySQL backend (vps-migration/api.php + storage.php) ---
// Only needed once you've moved off Supabase onto your own VPS database.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'socialflow');
define('DB_USER', 'socialflow_app');
define('DB_PASS', 'CHANGE_ME');

// Shared secret checked against the "apikey" / "Authorization: Bearer" header,
// equivalent to Supabase's anon key. Generate with: openssl rand -hex 32
define('API_KEY', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');

// Where uploaded files are written / served from.
define('STORAGE_ROOT', __DIR__ . '/uploads');
define('STORAGE_PUBLIC_URL', 'https://yourdomain.com/storage/public');

// GitHub webhook auto-deploy (webhook.php) — secret shared with the GitHub
// repo's webhook settings. Generate with: openssl rand -hex 32
define('GITHUB_WEBHOOK_SECRET', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');

// Web Push (push-send.php) — VAPID keypair, identifies this server to push
// services (FCM/Mozilla autopush) so it can send notifications even when the
// app is fully closed. Generate with: npx web-push generate-vapid-keys
// The public key must also be copied into VAPID_PUBLIC_KEY in app.jsx.
define('VAPID_PUBLIC_KEY',  'YOUR_VAPID_PUBLIC_KEY');
define('VAPID_PRIVATE_KEY', 'YOUR_VAPID_PRIVATE_KEY');
define('VAPID_SUBJECT',     'mailto:admin@admepro.com');

// Recruitment inbox (imap-recruitment-cron.php) — polls this mailbox for
// application emails and turns them into job_applications rows. Requires
// the PHP imap extension. Get these from your email provider's IMAP
// settings page (e.g. Hostinger: imap.hostinger.com, port 993, SSL).
define('RECRUITMENT_IMAP_HOST',     'imap.hostinger.com');
define('RECRUITMENT_IMAP_PORT',     993);
define('RECRUITMENT_IMAP_EMAIL',    'hr@admepro.com');
define('RECRUITMENT_IMAP_PASSWORD', 'CHANGE_ME');
