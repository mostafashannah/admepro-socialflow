<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// READ-ONLY. Asks Meta how it sees a WhatsApp number (so we know how it can be connected to SocialFlow).
//   php wa-number-status.php 270912611545723      the number with this Phone Number ID (and, for comparison, the Pro number)
// Uses the WhatsApp token already in config.php. Nothing is changed and no token is printed.
// ================================================================
require_once __DIR__ . '/config.php';
$ids = array_slice($argv, 1);
if (!$ids) { echo "Usage: php wa-number-status.php <phone number id> [more ids]\n"; exit(1); }
if (defined('WA_PHONE_ID') && WA_PHONE_ID && !in_array((string)WA_PHONE_ID, $ids, true)) $ids[] = (string)WA_PHONE_ID;
if (!defined('WA_ACCESS_TOKEN') || !WA_ACCESS_TOKEN) { echo "WA_ACCESS_TOKEN is not set in config.php\n"; exit(1); }
$fields = 'display_phone_number,verified_name,name_status,status,quality_rating,platform_type,code_verification_status,messaging_limit_tier,throughput,account_mode,is_official_business_account';
foreach ($ids as $id) {
    $id = preg_replace('/\D/', '', $id);
    $ch = curl_init("https://graph.facebook.com/v23.0/{$id}?" . http_build_query(['fields' => $fields]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_ACCESS_TOKEN]]);
    $res = json_decode((string)curl_exec($ch), true); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "== Phone Number ID {$id}" . ((defined('WA_PHONE_ID') && (string)WA_PHONE_ID === $id) ? "  (this is the Pro number)" : '') . "\n";
    if ($code !== 200 || !is_array($res)) { echo "   Meta answered HTTP {$code}: " . ($res['error']['message'] ?? 'no readable answer') . "\n   (If it says the object does not exist or you lack permission, the token in config.php can't see this number: it belongs to another WhatsApp account.)\n\n"; continue; }
    foreach (explode(',', $fields) as $f) if (array_key_exists($f, $res)) echo sprintf("   %-28s %s\n", $f, is_array($res[$f]) ? json_encode($res[$f]) : var_export($res[$f], true));
    echo "\n";
}
