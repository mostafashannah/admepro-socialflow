<?php
// ================================================================
// SocialFlow — one-time backfill: run the same lead-capture
// classification (maybeCaptureClientContact / maybeCreateLeadFromMessage)
// against every existing inbound conversation thread in customer_messages,
// for every managed client (+ admepro's own inbox), going all the way back
// to when each integration was first connected.
//
// A thread is marked "seen" in lead_backfill_seen once checked, regardless
// of whether it turned out to be an actual lead — so re-running the script
// never re-classifies (and re-bills Claude for) the same thread twice, even
// ones correctly classified as "other" and not captured.
//
// Processes at most `limit` unseen threads per call (default 15) so each
// request finishes well within nginx's timeout — call it repeatedly (same
// command) until "remaining" is 0.
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
// LEFT JOINs out anything already in lead_backfill_seen so the SQL itself
// only ever returns work still left to do.
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
    LEFT JOIN lead_backfill_seen seen ON seen.client_id = cm.client_id
             AND seen.channel = cm.channel AND seen.customer_id = cm.customer_id
    WHERE cm.direction = 'in' AND seen.id IS NULL
    ORDER BY cm.client_id, cm.channel, cm.customer_id
";
$stmt = $pdo->prepare($sql);
$stmt->execute($onlyClientId ? [':cid' => $onlyClientId] : []);
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$remainingTotal = count($threads);
$batch = array_slice($threads, 0, $limit);

$markSeen = $pdo->prepare("INSERT IGNORE INTO lead_backfill_seen (id, client_id, channel, customer_id) VALUES (UUID(), :cid, :ch, :custid)");
$leadCountFor = function(string $clientId) use ($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE client_id = :cid");
    $stmt->execute([':cid' => $clientId]);
    return (int)$stmt->fetchColumn();
};

$capturedNow = 0;
$results = [];

foreach ($batch as $t) {
    $isOwn = strcasecmp((string)$t['client_name'], 'admepro') === 0;
    $before = $leadCountFor($t['client_id']);

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

    $markSeen->execute([':cid' => $t['client_id'], ':ch' => $t['channel'], ':custid' => $t['customer_id']]);

    if ($leadCountFor($t['client_id']) > $before) {
        $capturedNow++;
        $results[] = ['client_name' => $t['client_name'], 'channel' => $t['channel'], 'customer_name' => $t['customer_name']];
    }
}

echo json_encode([
    'ok' => true,
    'threads_attempted_this_call' => count($batch),
    'new_leads_captured_this_call' => $capturedNow,
    'remaining' => max(0, $remainingTotal - count($batch)),
    'captured' => $results,
]);
