<?php
// One-off manual test — sends a sample "Pick an interview time" email with a
// MIX of a real ISO datetime slot and a free-text flexible-window slot (the
// exact combination that used to render "Invalid Date"), so you can confirm
// in your inbox that both now render correctly.
// Run: php test-send-interview-email.php you@example.com
// Delete this file after testing; it's not part of the app.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recruitment-mail-lib.php';

$to = $argv[1] ?? '';
if (!$to) { fwrite(STDERR, "Usage: php test-send-interview-email.php you@example.com\n"); exit(1); }

function fmtDateTimeLike($iso) {
    $ts = strtotime($iso);
    return $ts ? date('D, M j \a\t g:i A', $ts) : $iso;
}

$slots = [
    '2026-07-27T13:00:00',                        // real ISO datetime
    'Next working day, 1pm–6pm (any time)',       // free-text flexible window
    '2026-07-29T15:30:00',                        // real ISO datetime
];
$slotsHtml = implode('', array_map(fn($s) => '<li>' . fmtDateTimeLike($s) . '</li>', $slots));

$bodyHtml = '
    <h2 style="margin:0 0 8px;font-size:20px;font-weight:800;color:#111827">Hi there,</h2>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#4b5563">We\'d love to schedule an interview with you for the Content Creator position. Here are a few times that work for us:</p>
    <ul style="margin:0 0 16px;padding-left:18px;font-size:14px;line-height:1.8;color:#4b5563">' . $slotsHtml . '</ul>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#4b5563">📍 The interview will take place at our office: <a href="https://maps.app.goo.gl/ucJfFhpLMmozAfPA8" style="color:#d90b2c;text-decoration:none;font-weight:600">145 El Banafsig 3, New Cairo, Cairo</a></p>
    <p style="margin:0 0 20px"><a href="#" style="display:inline-block;padding:12px 24px;background:#d90b2c;color:#ffffff;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none">Pick a Time</a></p>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#4b5563">If none of these work, the link lets you suggest a time that does.</p>
    <table width="100%" style="border-top:1px solid #e5e7eb;margin-top:24px;padding-top:20px"><tr><td>
      <p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#111827">Admepro Recruitment Team</p>
      <p style="margin:0;font-size:13px;color:#6b7280">145 El Banafsig 3, New Cairo, Cairo</p>
      <p style="margin:0;font-size:13px;color:#6b7280">hello@admepro.com &middot; +20 100 037 0140</p>
    </td></tr></table>';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$ok = send_recruitment_email($pdo, $to, 'TEST — Pick an interview time', $bodyHtml, 'Admepro Careers');
echo $ok ? "Sent OK — check your inbox for real (non-Invalid) dates\n" : "FAILED — check error_log\n";
