<?php
// Shared by pro-lib.php, ai-proxy.php, and openai-proxy.php — WhatsApps
// every active admin the moment an AI provider call fails for a billing/
// quota reason ("credit balance too low", "insufficient_quota", etc.), and
// persists that state to app_settings.ai_outage_status so the Dashboard can
// show an in-app warning banner (with a straight link to add credit) rather
// than relying on someone having noticed the WhatsApp alert. Before this
// existed, that failure was silently swallowed into a generic "Sorry, that
// took too long to look up" reply (or a vague "AI error" in the app) with
// nothing surfaced anywhere except a PHP error log nobody was checking, so
// an exhausted Anthropic/OpenAI balance could go unnoticed for hours while
// Pro and every AI feature across the app quietly failed.
require_once __DIR__ . '/config.php';

// Call this after EVERY provider call, success or failure — it both raises
// the alert on a billing-shaped failure and clears it the moment a call to
// that same provider succeeds again, so the banner doesn't outlive the
// actual outage once someone tops up credit.
function recordAiCallResult(string $provider, int $httpStatus, string $rawBody) {
    if ($httpStatus >= 200 && $httpStatus < 300) { clearAiOutageStatus($provider); return; }
    notifyAdminOfAiOutage($provider, $rawBody);
}

function clearAiOutageStatus(string $provider) {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $row = $pdo->query("SELECT id, ai_outage_status FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        $status = json_decode($row['ai_outage_status'] ?? '', true) ?: [];
        if (!isset($status[$provider])) return; // nothing to clear
        unset($status[$provider]);
        $stmt = $pdo->prepare("UPDATE app_settings SET ai_outage_status = :s WHERE id = :id");
        $stmt->execute([':s' => json_encode($status), ':id' => $row['id']]);
    } catch (Throwable $e) {
        error_log('[clearAiOutageStatus] failed: ' . $e->getMessage());
    }
}

function notifyAdminOfAiOutage(string $provider, string $rawErrorBody) {
    $billingSignals = ['credit balance', 'insufficient_quota', 'billing', 'exceeded your current quota', 'purchase credits'];
    $isBillingIssue = false;
    foreach ($billingSignals as $sig) { if (stripos($rawErrorBody, $sig) !== false) { $isBillingIssue = true; break; } }
    if (!$isBillingIssue) return; // rate limits/transient errors aren't worth surfacing a banner or waking anyone up for

    $snippet = mb_substr(trim($rawErrorBody), 0, 200);

    // Persist the flag every time (not just once per hour like the WhatsApp
    // ping below) — the Dashboard banner needs to reflect the CURRENT state,
    // and this is a cheap single-row update, not something worth throttling.
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $row = $pdo->query("SELECT id, ai_outage_status FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $status = json_decode($row['ai_outage_status'] ?? '', true) ?: [];
            if (!isset($status[$provider])) { $status[$provider] = ['detected_at' => gmdate('c'), 'message' => $snippet]; }
            $stmt = $pdo->prepare("UPDATE app_settings SET ai_outage_status = :s WHERE id = :id");
            $stmt->execute([':s' => json_encode($status), ':id' => $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('[notifyAdminOfAiOutage] status persist failed: ' . $e->getMessage());
    }

    // WhatsApp ping to admins stays throttled to once per provider per hour
    // via a lock file (not a DB row — this must still work if the DB itself
    // is part of the outage) so one bad patch of traffic doesn't spam admins
    // on every message.
    $lockFile = sys_get_temp_dir() . '/sf_ai_outage_notified_' . strtolower($provider) . '.lock';
    if (is_file($lockFile) && (time() - filemtime($lockFile)) < 3600) return;
    @touch($lockFile);

    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admins = $pdo->query("SELECT whatsapp_number FROM team_members WHERE role = 'admin' AND status = 'active' AND whatsapp_number IS NOT NULL AND whatsapp_number != ''")->fetchAll(PDO::FETCH_COLUMN);
        $msg = "⚠️ {$provider} API is failing — likely out of credits/quota:\n\n{$snippet}\n\nPro, chat, captions, and other AI features across SocialFlow are affected until this is fixed. A warning banner is now showing on the Dashboard too.";
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
