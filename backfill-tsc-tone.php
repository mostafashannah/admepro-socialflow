<?php
// One-off — re-runs TSC's Knowledge Profile analysis using the new, richer
// tone-of-voice prompt (formality, sentence rhythm, language mix, emoji
// habits, phrases used/avoided, real example lines) instead of the old
// generic adjective-list tone field. Mirrors uploadClientDoc's current
// logic exactly (700K-char window, up to 3 most recent docs, general_info).
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$client = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%TSC%'")->fetch(PDO::FETCH_ASSOC);
if (!$client) { echo "TSC not found.\n"; exit; }

$docStmt = $pdo->prepare("SELECT name, doc_type, content FROM client_documents WHERE client_id = ? ORDER BY created_at DESC LIMIT 3");
$docStmt->execute([$client['id']]);
$docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$docs) { echo "No documents found for TSC.\n"; exit; }

$isChatGPT = $docs[0]['doc_type'] === 'chatgpt';
$allText = implode("\n\n", array_map(fn($d) => "=== {$d['name']} ===\n" . ($d['content'] ?? ''), $docs));
echo "Using " . count($docs) . " document(s), combined length " . strlen($allText) . " chars.\n";

$toneInstruction = "detailed, actionable writing-voice guide for this client — not just adjectives. Cover: formality level, sentence length/rhythm, language mix (e.g. Arabic/English usage), emoji/punctuation habits, words or phrases they consistently use or avoid, and 1-2 short example phrases pulled directly from the chat if any real captions/copy appear. Write it as instructions a copywriter could follow.";

$prompt = $isChatGPT
    ? "You are analyzing a ChatGPT conversation that contains discussions about a client's brand and content strategy.\n\n"
        . "Client: {$client['name']}\nChatGPT Conversation:\n" . mb_substr($allText, 0, 700000) . "\n\n"
        . "Extract ONLY the useful client brief information from this conversation. Ignore generic ChatGPT responses. Focus on what was discussed about the client's brand, goals, audience, and content preferences. This is only part of a longer conversation if it was truncated — extract everything genuinely useful from what's shown, including specific concrete details (e.g. named branches/locations, specific products, exact pricing) not just generic brand descriptors.\n\n"
        . "Return ONLY valid JSON (no markdown, no explanation):\n"
        . '{"summary":"2-3 sentences about this client based on the chat","tone":"' . $toneInstruction . '","content_preferences":"what type of content they want","industry_context":"their industry and market","keywords":["kw1","kw2","kw3"],"priorities":["priority1","priority2"],"skills":[{"name":"Skill","confidence":80,"category":"Content"}],"dos":["do this","and this"],"donts":["avoid this","never this"],"target_audience":"who they are targeting","general_info":"any contacts, locations/branches, addresses, phone numbers, hours, or other general company facts mentioned — plain text, one fact per line. Empty string if none found."}'
    : "Analyze these client documents and extract a knowledge profile for: {$client['name']}\n\nDOCUMENTS:\n" . mb_substr($allText, 0, 700000)
        . "\n\nReturn ONLY valid JSON (no markdown, no explanation):\n{\"summary\":\"2-3 sentences about this client\",\"tone\":\"{$toneInstruction}\",\"content_preferences\":\"what they like\",\"industry_context\":\"their industry\",\"keywords\":[\"kw1\",\"kw2\"],\"priorities\":[\"p1\",\"p2\"],\"skills\":[{\"name\":\"Skill\",\"confidence\":85,\"category\":\"Content\"}],\"general_info\":\"any contacts, locations/branches, addresses, phone numbers, hours, or other general company facts mentioned — plain text, one fact per line. Empty string if none found.\"}";

$ch = curl_init("https://api.anthropic.com/v1/messages");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-sonnet-4-6', 'max_tokens' => 2000, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
]);
$res = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($status < 200 || $status >= 300) { echo "Claude call failed (HTTP {$status}): " . substr($res, 0, 500) . "\n"; exit; }

$data = json_decode($res, true);
$raw = '';
foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $raw .= $block['text']; }
if (!preg_match('/\{[\s\S]*\}/', $raw, $m)) { echo "No JSON in Claude's response: {$raw}\n"; exit; }
$parsed = json_decode($m[0], true);
if (!$parsed) { echo "Failed to parse JSON.\n"; exit; }

echo "\nNew tone extracted:\n" . ($parsed['tone'] ?? '(none)') . "\n";

$existing = $pdo->prepare("SELECT id, version FROM client_knowledge WHERE client_id = ?");
$existing->execute([$client['id']]);
$existingRow = $existing->fetch(PDO::FETCH_ASSOC);

// Only touch the tone field (plus general_info, since it's part of the same
// prompt and equally cheap to refresh) — leave everything else untouched.
$fields = [
    'tone' => $parsed['tone'] ?? '',
    'last_analyzed' => date('Y-m-d H:i:s'),
    'analyzed_by' => 'backfill-tsc-tone-script',
];
if (!empty($parsed['general_info'])) $fields['general_info'] = $parsed['general_info'];

if (!$existingRow) { echo "No client_knowledge row exists for TSC — nothing to update.\n"; exit; }

$sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($fields)));
$stmt = $pdo->prepare("UPDATE client_knowledge SET {$sets}, version = version + 1 WHERE id = :id");
$stmt->execute([...$fields, 'id' => $existingRow['id']]);

echo "\nDone. TSC's tone field updated.\n";
