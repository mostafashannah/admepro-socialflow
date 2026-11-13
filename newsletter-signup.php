<?php
/** Public newsletter signup for admepro.com. POST JSON/form {email, name?, phone?, wa_opt_in?, lang?, website(honeypot)}. */
require_once __DIR__ . '/growth-lib.php';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https://(www\.)?admepro\.com$#', $origin)) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); header('Access-Control-Allow-Headers: Content-Type'); header('Access-Control-Allow-Methods: POST, OPTIONS'); }
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') gOut(405, ['error' => 'POST only']);
$in = gBody() ?: $_POST;
if (!empty($in['website'])) gOut(200, ['ok' => true]); // bot
$email = gEmail($in['email'] ?? ''); if (!$email) gOut(400, ['error' => 'Please enter a valid email']);
// simple per-IP rate limit: 5 per 10 minutes
$f = sys_get_temp_dir() . '/sf-nl-' . md5($_SERVER['REMOTE_ADDR'] ?? '') ; $hits = array_filter((array)json_decode(@file_get_contents($f) ?: '[]', true), fn($t) => $t > time() - 600);
if (count($hits) >= 5) gOut(429, ['error' => 'Too many attempts, try again later']);
$hits[] = time(); @file_put_contents($f, json_encode(array_values($hits)));
try {
    gUpsertContact(gPdo(), ['email' => $email, 'name' => mb_substr(trim($in['name'] ?? ''), 0, 120) ?: null, 'phone' => $in['phone'] ?? '', 'lang' => ($in['lang'] ?? '') === 'ar' ? 'ar' : 'en',
        'source' => 'website', 'tags' => 'newsletter', 'consent' => 'opt_in', 'wa_opt_in' => !empty($in['wa_opt_in']) && !empty($in['phone']),
        'consent_note' => 'Website signup ' . gNow() . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' ' . mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 120)]);
    gOut(200, ['ok' => true]);
} catch (Throwable $e) { error_log('[newsletter] ' . $e->getMessage()); gOut(500, ['error' => 'Could not subscribe right now']); }
