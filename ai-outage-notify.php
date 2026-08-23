<?php
// Shared by pro-lib.php, ai-proxy.php, and openai-proxy.php — WhatsApps
// every active admin the moment an AI provider call fails for a billing/
// quota reason ("credit balance too low", "insufficient_quota", etc.).
// Before this existed, that failure was silently swallowed into a generic
// "Sorry, that took too long to look up" reply (or a vague "AI error" in
// the app) with nothing surfaced anywhere except a PHP error log nobody
// was checking, so an exhausted Anthropic/OpenAI balance could go
// unnoticed for hours while Pro and every AI feature across the app
// quietly failed. Throttled to once per provider per hour via a lock file
// (not a DB row — this must still work if the DB itself is part of the
// outage) so one bad patch of traffic doesn't spam admins on every message.
require_once __DIR__ . '/config.php';

function notifyAdminOfAiOutage(string $provider, string $rawErrorBody) {
    $billingSignals = ['credit balance', 'insufficient_quota', 'billing', 'exceeded your current quota', 'purchase credits'];
    $isBillingIssue = false;
    foreach ($billingSignals as $sig) { if (stripos($rawErrorBody, $sig) !== false) { $isBillingIssue = true; break; } }
    if (!$isBillingIssue) return; // rate limits/transient errors aren't worth waking anyone up for

    $lockFile = sys_get_temp_dir() . '/sf_ai_outage_notified_' . strtolower($provider) . '.lock';
    if (is_file($lockFile) && (time() - filemtime($lockFile)) < 3600) return;
    @touch($lockFile);

    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admins = $pdo->query("SELECT whatsapp_number FROM team_members WHERE role = 'admin' AND status = 'active' AND whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll(PDO::FETCH_COLUMN);
        $snippet = mb_substr(trim($rawErrorBody), 0, 200);
        $msg = "⚠️ {$provider} API is failing — likely out of credits/quota:\n\n{$snippet}\n\nPro, chat, captions, and other AI features across SocialFlow are affected until this is fixed.";
        if (!defined('WA_PHONE_ID') || !defined('WA_ACCESS_TOKEN') || !WA_PHONE_ID || !WA_ACCESS_TOKEN) return;
        foreach ($admins as $waNumber) {
            $to = preg_replace('/[\s\-\(\)]+/', '', $waNumber);
            $to = '+' . preg_replace('/\D/', '', $to);
            $ch = curl_init('https://graph.facebook.com/v19.0/' . WA_PHONE_ID . '/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $msg]]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_ACCESS_TOKEN],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {
        error_log('[notifyAdminOfAiOutage] failed: ' . $e->getMessage());
    }
}
