<?php
// ================================================================
// SocialFlow — one-time backfill: run the same lead-capture
// classification (maybeCaptureClientContact) against every existing
// inbound conversation thread in customer_messages, for every managed
// client, going all the way back to when each integration was first
// connected. Idempotent — safe to re-run, skips threads already
// captured (same src_id-tag dedup used by the live webhook path).
//
// Run: curl -X POST https://socialflow.admepro.com/backfill-leads.php -H "apikey: <API_KEY>"
// Optional: ?client_id=<uuid> to backfill just one client.
// ================================================================
require_once 'config.php';
require_once 'reply-bot-lib.php';
header("Content-Type: application/json");
set_time_limit(0); // this can take a while — many Claude calls, one per thread

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

// One row per distinct thread (client + channel + customer), with the most
// recent inbound message's text/name — maybeCaptureClientContact() pulls the
// full thread context itself, this just needs to trigger it once per thread.
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
";
$stmt = $pdo->prepare($sql);
$stmt->execute($onlyClientId ? [':cid' => $onlyClientId] : []);
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$results = [];

foreach ($threads as $t) {
    $isOwn = strcasecmp((string)$t['client_name'], 'admepro') === 0;

    // admepro's own inbox uses a different classifier (isInterestedInOurServices,
    // via maybeCreateLeadFromMessage) — "is this person interested in hiring US",
    // not the lead/service_provider/hiring categorization used for managed clients.
    $beforeCount = $isOwn
        ? (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE notes LIKE " . $pdo->quote('%src_id:' . $t['channel'] . ':' . $t['customer_id'] . '%'))->fetchColumn()
        : (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE client_id = " . $pdo->quote($t['client_id']))->fetchColumn();

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

    $afterCount = $isOwn
        ? (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE notes LIKE " . $pdo->quote('%src_id:' . $t['channel'] . ':' . $t['customer_id'] . '%'))->fetchColumn()
        : (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE client_id = " . $pdo->quote($t['client_id']))->fetchColumn();
    $processed++;
    if ($afterCount > $beforeCount) {
        $results[] = ['client_name' => $t['client_name'], 'channel' => $t['channel'], 'customer_name' => $t['customer_name']];
    }
}

echo json_encode([
    'ok' => true,
    'threads_processed' => $processed,
    'new_leads_captured' => count($results),
    'captured' => $results,
]);
