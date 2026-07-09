<?php
// ================================================================
// Receives Messenger + Instagram DM webhook events from Meta and
// stores them per-client in customer_messages, by matching the
// page_id in the event against integrations.credentials.page_id.
// ================================================================
require_once 'config.php';
require_once 'reply-bot-lib.php';

// ── Webhook verification (Meta calls this once when you subscribe) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && $token === META_WEBHOOK_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$raw = file_get_contents('php://input');

// ── Verify request signature (X-Hub-Signature-256) ──
// Facebook Pages and Instagram (API with Instagram login) are separate Meta
// apps with separate secrets — a webhook event can be signed with either one
// depending on which app's dashboard the subscription was configured under.
// Accept the request if it matches either app's secret.
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!$sig) {
    http_response_code(403);
    exit;
}
$secrets = array_filter([
    defined('META_APP_SECRET') ? META_APP_SECRET : null,
    defined('INSTAGRAM_APP_SECRET') ? INSTAGRAM_APP_SECRET : null,
]);
$signatureValid = false;
foreach ($secrets as $secret) {
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    if (hash_equals($expected, $sig)) { $signatureValid = true; break; }
}
if (!$signatureValid) {
    http_response_code(403);
    exit;
}

$body = json_decode($raw, true);
http_response_code(200); // ack immediately, Meta requires a fast 200
echo 'EVENT_RECEIVED';

// Actually close the connection to Meta here instead of just queuing the
// response — without this, the script kept running synchronously through
// every entry (Meta can batch multiple pages into one POST), so a slow
// Claude call for one client's message could delay/timeout processing of
// another client's message later in the same batch.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
set_time_limit(0);

if (!$body || empty($body['entry'])) { exit; }

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function findClientByPageId(PDO $pdo, string $pageId) {
    $stmt = $pdo->prepare("SELECT id, name, credentials, client_id, client_name FROM integrations WHERE status='active' AND app_key IN ('facebook','instagram')");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $creds = json_decode($row['credentials'] ?? '{}', true) ?: [];
        // Instagram (API with Instagram login) webhook events arrive keyed by the
        // legacy Instagram-Business-Account ID, which can differ from the new-style
        // user ID returned by graph.instagram.com/me and stored as page_id during
        // OAuth — page_id_alt lets a connected account match on either ID.
        if (($creds['page_id'] ?? '') === $pageId || ($creds['page_id_alt'] ?? '') === $pageId) {
            // The "Connect" wizard always requires picking a real client row, so
            // admepro's own page is connected via a client literally named "admepro"
            // (client_id is never actually NULL in practice) — match on that name,
            // falling back to NULL/empty client_id in case that ever changes.
            $clientName = (string)($row['client_name'] ?? '');
            return [
                'client_id' => $row['client_id'],
                'client_name' => $row['client_name'],
                'access_token' => $creds['access_token'] ?? null,
                'is_own' => $row['client_id'] === null || $row['client_id'] === '' || strcasecmp($clientName, 'admepro') === 0,
            ];
        }
    }
    return null;
}

// Looks up the sender's display name from Meta's profile API so the inbox
// shows a real name instead of the raw PSID/IGSID. Best-effort — a failed
// lookup just leaves the name blank rather than blocking message storage.
function fetchSenderName(string $channel, ?string $accessToken, string $senderId) {
    if (!$accessToken) return null;
    // Instagram-Login API tokens ("IGAA..." prefix) are scoped to graph.instagram.com;
    // Page/Messenger tokens use graph.facebook.com — same split as meta-send-reply.php.
    $host = ($channel === 'instagram' && str_starts_with($accessToken, 'IGAA')) ? 'graph.instagram.com' : 'graph.facebook.com';
    $fields = $channel === 'instagram' ? 'name,username' : 'first_name,last_name';
    $url = "https://{$host}/v19.0/{$senderId}?" . http_build_query(['fields' => $fields, 'access_token' => $accessToken]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 8]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (!$data || isset($data['error'])) return null;
    if ($channel === 'instagram') return $data['name'] ?? ($data['username'] ?? null);
    $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
    return $name ?: null;
}

// Story replies/mentions arrive as a normal DM in the messaging webhook, but with
// no indication in the bare text that it's a reaction to a story rather than a
// regular message — tag it so the inbox and the reply bot both know the context
// ("🔥" reads very differently as a story reply vs. an out-of-nowhere DM).
function storyContextTag(array $message) {
    if (!empty($message['reply_to']['story'])) return '[Replied to your Instagram story]';
    foreach (($message['attachments'] ?? []) as $att) {
        if (($att['type'] ?? '') === 'story_mention') return '[Mentioned you in their story]';
    }
    return null;
}

// Fetches the caption of the post/reel a comment was left on, so the reply bot
// knows what the comment is actually reacting to (a hiring post vs. a service
// promo vs. a product reel all warrant very different replies) instead of only
// ever seeing the bare comment text in isolation.
function fetchPostCaption(string $channel, ?string $accessToken, string $mediaOrPostId) {
    if (!$accessToken || !$mediaOrPostId) return null;
    $host = ($channel === 'ig_comment' && str_starts_with($accessToken, 'IGAA')) ? 'graph.instagram.com' : 'graph.facebook.com';
    $field = $channel === 'ig_comment' ? 'caption' : 'message';
    $url = "https://{$host}/v19.0/{$mediaOrPostId}?" . http_build_query(['fields' => $field, 'access_token' => $accessToken]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 8]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (!$data || isset($data['error'])) return null;
    return $data[$field] ?? null;
}

function storeMessage(PDO $pdo, $clientId, $clientName, $channel, $customerId, $customerName, $text, $externalId = null) {
    $stmt = $pdo->prepare("INSERT INTO customer_messages (client_id, client_name, channel, customer_id, customer_name, direction, message_text, sent_by, thread_status, external_id) VALUES (:cid, :cname, :ch, :custid, :custname, 'in', :txt, 'customer', 'needs_human', :eid)");
    $stmt->execute([':cid'=>$clientId, ':cname'=>$clientName, ':ch'=>$channel, ':custid'=>$customerId, ':custname'=>$customerName, ':txt'=>$text, ':eid'=>$externalId]);
}

// Meta's Messenger PSID-name lookup (fetchSenderName) is unreliable and often fails
// on a customer's first message, leaving the inbox showing their raw numeric ID
// forever even though a later lookup for the same customer_id would have worked —
// backfill every earlier row for this thread once a name lookup finally succeeds.
function backfillCustomerName(PDO $pdo, $clientId, $channel, $customerId, $customerName) {
    if (!$customerName) return;
    $stmt = $pdo->prepare("UPDATE customer_messages SET customer_name = :name WHERE client_id = :cid AND channel = :ch AND customer_id = :custid AND (customer_name IS NULL OR customer_name = '')");
    $stmt->execute([':name' => $customerName, ':cid' => $clientId, ':ch' => $channel, ':custid' => $customerId]);
}

// Meta tells us the platform at the top level — Instagram DMs arrive under
// entry.messaging using the exact same shape as Messenger, so the array shape
// alone can't be used to label the channel; $body['object'] can.
$channel = ($body['object'] ?? '') === 'instagram' ? 'instagram' : 'messenger';

try {
    foreach ($body['entry'] as $entry) {
        $pageId = $entry['id'] ?? '';
        if (!$pageId) continue;
        $client = findClientByPageId($pdo, $pageId);
        if (!$client) {
            // Logged so a silently-dropped event (e.g. the connected account's stored
            // page_id no longer matches the ID Meta sends in entry.id after a
            // reconnect) is diagnosable from the server log instead of just vanishing.
            error_log("meta-inbox-webhook: no integration matched entry.id={$pageId} object=" . ($body['object'] ?? '?'));
            continue;
        }

        foreach (($entry['messaging'] ?? []) as $m) {
            $msgData = $m['message'] ?? [];
            // Meta also fires a webhook event for messages the Page itself sends (an
            // "echo", is_echo:true, sender.id = the Page) — without this skip, the
            // bot's own auto-reply got webhooked right back in and was treated as a
            // brand-new inbound message from "the customer", re-triggering lead
            // capture/auto-reply on the bot's own text.
            if (!empty($msgData['is_echo'])) continue;
            $text = $msgData['text'] ?? null;
            $senderId = $m['sender']['id'] ?? null;
            if ($tag = storyContextTag($msgData)) { $text = $text ? "{$tag} {$text}" : $tag; }
            if ($text && $senderId) {
                $senderName = fetchSenderName($channel, $client['access_token'], $senderId);
                backfillCustomerName($pdo, $client['client_id'], $channel, $senderId, $senderName);
                storeMessage($pdo, $client['client_id'], $client['client_name'], $channel, $senderId, $senderName, $text);
                try {
                    if ($client['is_own']) { maybeCreateLeadFromMessage($pdo, $channel, $senderId, $senderName, $text, null, $client['client_id']); }
                    else { maybeCaptureClientContact($pdo, $channel, $senderId, $senderName, $text, $client['client_id'], $client['client_name']); }
                    maybeAutoReply($pdo, $client['client_id'], $client['client_name'], $channel, $senderId, $senderName);
                } catch (\Throwable $e) { error_log('meta-inbox-webhook reply-bot EXCEPTION: ' . $e->getMessage()); }
            }
        }

        // Some Instagram setups deliver via "changes" with field "messages" instead.
        foreach (($entry['changes'] ?? []) as $c) {
            $field = $c['field'] ?? '';

            if ($field === 'messages') {
                $msgData = $c['value']['message'] ?? [];
                if (!empty($msgData['is_echo'])) continue;
                $text = $msgData['text'] ?? null;
                $senderId = $c['value']['sender']['id'] ?? null;
                if ($tag = storyContextTag($msgData)) { $text = $text ? "{$tag} {$text}" : $tag; }
                // $channel (computed above from $body['object']) is 'instagram' or 'messenger' —
                // this "changes" delivery shape isn't Instagram-exclusive, so hardcoding
                // 'instagram' here mis-tagged real Facebook Messenger events, storing them
                // under the wrong channel and sending replies with the wrong integration's
                // credentials (Instagram's token/page_id instead of Facebook's).
                if ($text && $senderId) {
                    $senderName = fetchSenderName($channel, $client['access_token'], $senderId);
                    backfillCustomerName($pdo, $client['client_id'], $channel, $senderId, $senderName);
                    storeMessage($pdo, $client['client_id'], $client['client_name'], $channel, $senderId, $senderName, $text);
                    try {
                        if ($client['is_own']) { maybeCreateLeadFromMessage($pdo, $channel, $senderId, $senderName, $text, null, $client['client_id']); }
                        else { maybeCaptureClientContact($pdo, $channel, $senderId, $senderName, $text, $client['client_id'], $client['client_name']); }
                        maybeAutoReply($pdo, $client['client_id'], $client['client_name'], $channel, $senderId, $senderName);
                    } catch (\Throwable $e) { error_log('meta-inbox-webhook reply-bot EXCEPTION: ' . $e->getMessage()); }
                }
                continue;
            }

            // Facebook Page comments arrive via field "feed" (item=comment, verb=add).
            if ($field === 'feed' && ($c['value']['item'] ?? '') === 'comment' && ($c['value']['verb'] ?? 'add') === 'add') {
                $v = $c['value'];
                $commentId = $v['comment_id'] ?? null;
                $fromId    = $v['from']['id'] ?? null;
                $text      = $v['message'] ?? null;
                // Skip comments authored by the Page itself — otherwise the bot's own
                // public replies (posted as the Page) would re-trigger another reply.
                if ($commentId && $fromId && $fromId !== $pageId && $text !== null && $text !== '') {
                    $fromName = $v['from']['name'] ?? null;
                    $postId = $v['post_id'] ?? null;
                    $caption = fetchPostCaption('fb_comment', $client['access_token'], $postId);
                    storeMessage($pdo, $client['client_id'], $client['client_name'], 'fb_comment', $fromId, $fromName, $text, $commentId);
                    try {
                        if (!$client['is_own']) { maybeCaptureClientContact($pdo, 'fb_comment', $fromId, $fromName, $text, $client['client_id'], $client['client_name']); }
                        maybeAutoReply($pdo, $client['client_id'], $client['client_name'], 'fb_comment', $fromId, $fromName, $commentId, $caption);
                    } catch (\Throwable $e) { error_log('meta-inbox-webhook reply-bot EXCEPTION: ' . $e->getMessage()); }
                }
                continue;
            }

            // Instagram comments arrive via field "comments".
            if ($field === 'comments') {
                $v = $c['value'];
                $commentId = $v['id'] ?? null;
                $fromId    = $v['from']['id'] ?? null;
                $text      = $v['text'] ?? null;
                if ($commentId && $fromId && $fromId !== $pageId && $text !== null && $text !== '') {
                    $fromName = $v['from']['username'] ?? null;
                    $mediaId = $v['media']['id'] ?? null;
                    $caption = fetchPostCaption('ig_comment', $client['access_token'], $mediaId);
                    storeMessage($pdo, $client['client_id'], $client['client_name'], 'ig_comment', $fromId, $fromName, $text, $commentId);
                    try {
                        if (!$client['is_own']) { maybeCaptureClientContact($pdo, 'ig_comment', $fromId, $fromName, $text, $client['client_id'], $client['client_name']); }
                        maybeAutoReply($pdo, $client['client_id'], $client['client_name'], 'ig_comment', $fromId, $fromName, $commentId, $caption);
                    } catch (\Throwable $e) { error_log('meta-inbox-webhook reply-bot EXCEPTION: ' . $e->getMessage()); }
                }
            }
        }
    }
} catch (\Throwable $e) {
    error_log('meta-inbox-webhook EXCEPTION: ' . $e->getMessage());
}
