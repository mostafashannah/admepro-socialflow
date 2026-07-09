<?php
// ================================================================
// SocialFlow — one-time backfill: run the same lead-capture
// classification (maybeCaptureClientContact / maybeCreateLeadFromMessage)
// against every existing inbound conversation thread in customer_messages,
// for every managed client (+ admepro's own inbox), going all the way back
// to when each integration was first connected. Idempotent — safe to
// re-run, skips threads already captured (same src_id-tag dedup used by
// the live webhook path).
//
// Processes at most `limit` NOT-yet-captured threads per call (default 15)
// so each request finishes well within nginx's timeout — call it repeatedly
// (same command) until "remaining" is 0.
//
// Run: curl -X POST https://socialflow.admepro.com/backfill-leads.php -H "apikey: <API_KEY>"
// Optional: ?client_id=<uuid> to backfill just one client, &limit=N to change batch size.
// ================================================================
require_once 'config.php';
require_once 'reply-bot-lib.php';
header("Content-Type: application/json");
set_time_limit(0);

$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$onlyClientId = $_GET['client_id'] ?? null;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 15;

// One row per distinct thread (client + channel + customer), with the most
// recent inbound message's text/name — the capture functions pull the full
// thread context themselves, this just needs to trigger them once per thread.
$sql = "
    SELECT cm.client_id, cm.client_name, cm.channel, cm.customer_id, cm.customer_name, cm.message_text
    FROM customer_messages cm
    INNER JOIN (
        SELECT client_id, channel, customer_id, MAX(created_at) AS max_created
        FROM customer_messages
        WHERE direction = 'in' AND client_id IS NOT NULL AND client_id != ''
        " . ($onlyClientId ? "AND client_id = :cid" : "") . "
        GROUP BY client_id, channel, customer_id
    ) latest ON latest.client_id = cm.client_id AND latest.channel = cm.channel
             AND latest.customer_id = cm.customer_id AND latest.max_created = cm.created_at
    WHERE cm.direction = 'in'
    ORDER BY cm.client_id, cm.channel, cm.customer_id
";
$stmt = $pdo->prepare($sql);
$stmt->execute($onlyClientId ? [':cid' => $onlyClientId] : []);
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

function alreadyCaptured(PDO $pdo, bool $isOwn, string $clientId, string $channel, string $customerId) {
    $tag = '%src_id:' . $channel . ':' . $customerId . '%';
    if ($isOwn) {
        return (bool)$pdo->query("SELECT 1 FROM leads WHERE notes LIKE " . $pdo->quote($tag) . " LIMIT 1")->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT 1 FROM leads WHERE client_id = :cid AND notes LIKE :tag LIMIT 1");
    $stmt->execute([':cid' => $clientId, ':tag' => $tag]);
    return (bool)$stmt->fetchColumn();
}

$attempted = 0;
$capturedNow = 0;
$skippedAlready = 0;
$results = [];
$remaining = 0;

foreach ($threads as $t) {
    $isOwn = strcasecmp((string)$t['client_name'], 'admepro') === 0;

    if (alreadyCaptured($pdo, $isOwn, $t['client_id'], $t['channel'], $t['customer_id'])) {
        $skippedAlready++;
        continue;
    }

    if ($attempted >= $limit) { $remaining++; continue; } // count how many are left for the next call

    $phone = $t['channel'] === 'whatsapp' ? $t['customer_id'] : null;
    try {
        if ($isOwn) {
            maybeCreateLeadFromMessage($pdo, $t['channel'], $t['customer_id'], $t['customer_name'], (string)$t['message_text'], $phone, $t['client_id']);
        } else {
            maybeCaptureClientContact($pdo, $t['channel'], $t['customer_id'], $t['customer_name'], (string)$t['message_text'], $t['client_id'], $t['client_name'], $phone);
        }
    } catch (\Throwable $e) {
        error_log('backfill-leads EXCEPTION: ' . $e->getMessage());
    }
    $attempted++;

    if (alreadyCaptured($pdo, $isOwn, $t['client_id'], $t['channel'], $t['customer_id'])) {
        $capturedNow++;
        $results[] = ['client_name' => $t['client_name'], 'channel' => $t['channel'], 'customer_name' => $t['customer_name']];
    }
}

echo json_encode([
    'ok' => true,
    'threads_attempted_this_call' => $attempted,
    'new_leads_captured_this_call' => $capturedNow,
    'already_captured_skipped' => $skippedAlready,
    'remaining' => $remaining,
    'captured' => $results,
]);
