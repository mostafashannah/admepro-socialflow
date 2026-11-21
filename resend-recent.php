<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// READ-ONLY. Shows (1) whether the sending domains are verified in Resend and (2) what happened to the most recent emails
// (delivered / bounced / complained / delayed ...). Use it when "I sent a test and it never arrived".
//   php resend-recent.php            last 20 emails
//   php resend-recent.php you@other-domain.com    only emails sent to that address
// Prints no keys. Nothing is sent or changed.
// ================================================================
require_once __DIR__ . '/config.php';
if (!defined('RESEND_API_KEY') || !RESEND_API_KEY) { echo "RESEND_API_KEY is not set in config.php\n"; exit(1); }
$filter = strtolower(trim($argv[1] ?? ''));
function rs($path) {
    $ch = curl_init('https://api.resend.com' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY]]);
    $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, json_decode((string)$r, true)];
}
[$c, $d] = rs('/domains');
echo "== Sending domains in Resend\n";
if ($c !== 200) echo "   Resend answered HTTP $c: " . ($d['message'] ?? 'no readable answer') . "\n";
foreach ((array)($d['data'] ?? []) as $x) echo sprintf("   %-28s status: %s\n", $x['name'] ?? '?', $x['status'] ?? '?');
echo "   (A domain must say \"verified\". Anything else means mail to other people can be rejected or sent to spam.)\n\n";
foreach (['MARKETING_FROM' => 'admepro <news@admepro.com>', 'SALES_FROM' => 'Admepro <sales@admepro.com>'] as $k => $def) echo "   $k = " . (defined($k) ? constant($k) : "$def (default)") . "\n";
echo "\n== Recent emails\n";
[$c, $d] = rs('/emails?limit=40');
if ($c !== 200) { echo "   Resend answered HTTP $c: " . ($d['message'] ?? 'no readable answer') . "\n"; exit(1); }
$n = 0;
foreach ((array)($d['data'] ?? []) as $m) {
    $to = strtolower(implode(', ', (array)($m['to'] ?? [])));
    if ($filter !== '' && strpos($to, $filter) === false) continue;
    echo sprintf("   %s  %-10s  to %-34s  from %-26s  %s\n", substr((string)($m['created_at'] ?? ''), 0, 16), (string)($m['last_event'] ?? '?'), mb_substr($to, 0, 34), mb_substr((string)($m['from'] ?? ''), 0, 26), mb_substr((string)($m['subject'] ?? ''), 0, 40));
    if (++$n >= 20) break;
}
if (!$n) echo "   No emails found" . ($filter !== '' ? " to $filter" : '') . ". If you sent a test, it never reached Resend.\n";
echo "\nlast_event: delivered = the other server accepted it (look in spam/junk); bounced = rejected; complained = marked as spam; delivery_delayed = still retrying; sent = handed over, no answer yet.\n";
