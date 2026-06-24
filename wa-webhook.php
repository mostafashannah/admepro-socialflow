<?php
// ================================================================
// SocialFlow — Inbound WhatsApp webhook ("Pro" via WhatsApp)
//
// Lets team members and clients message the SocialFlow number on WhatsApp
// and get a reply from "Pro" (the same assistant persona used in-app),
// with light context pulled from the live MySQL DB (who they are, their
// open tasks / pending approvals). This is a stateless MVP — each
// incoming message is answered independently; it does not carry chat
// history across messages.
//
// Setup in Meta App Dashboard → WhatsApp → Configuration:
//   Callback URL: https://yourdomain.com/wa-webhook.php
//   Verify token: same value as WA_VERIFY_TOKEN in config.php
//   Subscribe to the "messages" webhook field.
// ================================================================

require_once __DIR__ . '/config.php';

// ---- 1. Verification handshake (GET) ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && hash_equals(WA_VERIFY_TOKEN, (string)$token)) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ---- 2. Verify Meta's signature on the raw body ----
$raw = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $raw, WA_APP_SECRET);
if (!$signatureHeader || !hash_equals($expected, $signatureHeader)) {
    http_response_code(403);
    exit;
}

// Acknowledge immediately so Meta doesn't retry/timeout; do the work after.
http_response_code(200);
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$body = json_decode($raw, true);
$message = $body['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
if (!$message || $message['type'] !== 'text') {
    exit; // ignore statuses, non-text messages, etc.
}

$from = preg_replace('/\D/', '', $message['from'] ?? ''); // digits only, no '+'
$text = trim($message['text']['body'] ?? '');
if (!$from || !$text) exit;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    [$senderName, $senderRole, $contextBlock] = identifySender($pdo, $from);

    $reply = askPro($senderName, $senderRole, $contextBlock, $text);
    if ($reply) sendWhatsAppReply($from, $reply);
} catch (Throwable $e) {
    error_log('[wa-webhook] ' . $e->getMessage());
}
exit;

// Match the incoming phone number (digits only) against every place a
// WhatsApp/mobile number can live, and gather a short context block for Pro.
function identifySender(PDO $pdo, string $digits) {
    $like = '%' . substr($digits, -9) . '%'; // match on the last 9 digits to tolerate +country/leading-0 formatting differences

    $stmt = $pdo->prepare("SELECT id, name, role FROM team_members WHERE REPLACE(REPLACE(REPLACE(whatsapp_number,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tasks = $pdo->prepare("SELECT title, stage FROM posts WHERE assigned_to = :n AND stage NOT IN ('published','cancelled') ORDER BY scheduled_date ASC LIMIT 5");
        $tasks->execute([':n' => $m['name']]);
        $rows = $tasks->fetchAll(PDO::FETCH_ASSOC);
        $list = $rows ? implode("\n", array_map(fn($r) => "- {$r['title']} ({$r['stage']})", $rows)) : 'No open posts assigned right now.';
        return [$m['name'], 'team:' . $m['role'], "Their open assigned posts:\n$list"];
    }

    $stmt = $pdo->prepare("SELECT id, display_name, user_email FROM user_profiles WHERE REPLACE(REPLACE(REPLACE(whatsapp_number,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [$m['display_name'] ?: $m['user_email'], 'team:member', 'No additional context loaded for this profile.'];
    }

    $stmt = $pdo->prepare("SELECT id, name FROM clients WHERE REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pending = $pdo->prepare("SELECT title FROM posts WHERE client_id = :c AND stage = 'client_review' LIMIT 5");
        $pending->execute([':c' => $m['id']]);
        $rows = $pending->fetchAll(PDO::FETCH_ASSOC);
        $list = $rows ? implode("\n", array_map(fn($r) => "- {$r['title']}", $rows)) : 'Nothing waiting on their approval right now.';
        return [$m['name'], 'client', "Posts pending their approval:\n$list"];
    }

    $stmt = $pdo->prepare("SELECT id, name, client_name FROM client_users WHERE REPLACE(REPLACE(REPLACE(mobile,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [$m['name'], 'client', "They are a client-portal user for {$m['client_name']}."];
    }

    return [null, null, null];
}

function askPro($senderName, $senderRole, $contextBlock, $userText) {
    if (!$senderName) {
        // Unknown number — don't reveal app internals, just a generic reply.
        $system = "You are Pro, the AI assistant for SocialFlow (a social media agency management app). "
                . "This WhatsApp number isn't linked to any SocialFlow account yet. Politely tell the sender "
                . "to ask their SocialFlow admin to add their WhatsApp number to their profile, in one short message.";
    } else {
        $system = "You are Pro, the AI assistant built into SocialFlow. You are replying over WhatsApp to "
                . "{$senderName} (role: {$senderRole}). Keep replies short — a few sentences max, WhatsApp-style, "
                . "no markdown headers. You can see this context about them:\n\n{$contextBlock}\n\n"
                . "If asked to take an action you can't perform over WhatsApp (e.g. editing a post), tell them "
                . "to open the SocialFlow app to do it, but still answer their question as best you can here.";
    }

    $payload = json_encode([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 400,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $userText]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        error_log('[wa-webhook] Anthropic error: ' . $res);
        return null;
    }
    $data = json_decode($res, true);
    return $data['content'][0]['text'] ?? null;
}

function sendWhatsAppReply($to, $body) {
    if (!defined('WA_PHONE_ID') || !defined('WA_ACCESS_TOKEN') || !WA_PHONE_ID || !WA_ACCESS_TOKEN) return;
    $endpoint = 'https://graph.facebook.com/v19.0/' . WA_PHONE_ID . '/messages';
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $body],
    ]);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_ACCESS_TOKEN,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}
