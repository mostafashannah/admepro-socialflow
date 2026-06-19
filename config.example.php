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
