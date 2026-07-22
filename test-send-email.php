<?php
// One-off manual test for sendProEmail() (pro-lib.php) — confirms Resend is
// configured and delivering. Run: php test-send-email.php you@example.com
// Delete this file after testing; it's not part of the app.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php';

$to = $argv[1] ?? '';
if (!$to) { fwrite(STDERR, "Usage: php test-send-email.php you@example.com\n"); exit(1); }

$ok = sendProEmail($to, 'SocialFlow test email', '<p style="font-family:sans-serif">This is a test email from sendProEmail() — if you got this, Resend is working correctly.</p>');
echo $ok ? "Sent OK\n" : "FAILED — check error_log (grep sendProEmail /var/log/php8.3-fpm.log)\n";
