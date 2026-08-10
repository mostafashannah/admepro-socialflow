<?php
// One-off — re-runs the exact ChatGPT-chat analysis the app does on
// upload, using TSC's already-stored document content, now that
// client_knowledge actually has the context_file/dos/donts/target_audience
// columns to write into. No re-paste needed — the raw chat text is still
// sitting in client_documents.content from the original upload.
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$client = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%TSC%'")->fetch(PDO::FETCH_ASSOC);
if (!$client) { echo "TSC not found.\n"; exit; }

$doc = $pdo->prepare("SELECT id, name, content FROM client_documents WHERE client_id = ? AND doc_type = 'chatgpt' ORDER BY created_at DESC LIMIT 1");
$doc->execute([$client['id']]);
$docRow = $doc->fetch(PDO::FETCH_ASSOC);
if (!$docRow) { echo "No ChatGPT document found for TSC.\n"; exit; }
echo "Using document: {$docRow['name']} (content length " . strlen($docRow['content']) . ")\n";

$content = $docRow['content'] ?? '';
$prompt = "You are analyzing a ChatGPT conversation that contains discussions about a client's brand and content strategy.\n\n"
    . "Client: {$client['name']}\nChatGPT Conversation:\n" . mb_substr($content, 0, 6000) . "\n\n"
    . "Extract ONLY the useful client brief information from this conversation. Ignore generic ChatGPT responses. Focus on what was discussed about the client's brand, goals, audience, and content preferences.\n\n"
    . "Return ONLY valid JSON (no markdown, no explanation):\n"
    . '{"summary":"2-3 sentences about this client based on the chat","tone":"brand voice/communication style extracted from chat","content_preferences":"what type of content they want","industry_context":"their industry and market","keywords":["kw1","kw2","kw3"],"priorities":["priority1","priority2"],"skills":[{"name":"Skill","confidence":80,"category":"Content"}],"dos":["do this","and this"],"donts":["avoid this","never this"],"target_audience":"who they are targeting"}';

$ch = curl_init("https://api.anthropic.com/v1/messages");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-sonnet-4-6', 'max_tokens' => 1200, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
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
echo "Parsed analysis:\n" . json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$existing = $pdo->prepare("SELECT id, version, context_file FROM client_knowledge WHERE client_id = ?");
$existing->execute([$client['id']]);
$existingRow = $existing->fetch(PDO::FETCH_ASSOC);

$newSummary = "## ChatGPT Import\n" . ($parsed['summary'] ?? '') . "\n\n**Tone:** " . ($parsed['tone'] ?? '')
    . "\n**Keywords:** " . implode(', ', array_slice($parsed['keywords'] ?? [], 0, 6))
    . "\n**Priorities:** " . implode(', ', array_slice($parsed['priorities'] ?? [], 0, 3))
    . (!empty($parsed['dos']) ? "\n**Do's:** " . implode(', ', array_slice($parsed['dos'], 0, 2)) : '')
    . (!empty($parsed['donts']) ? "\n**Don'ts:** " . implode(', ', array_slice($parsed['donts'], 0, 2)) : '')
    . "\n**Audience:** " . ($parsed['target_audience'] ?? '');
$oldCtx = $existingRow['context_file'] ?? '';
$mergedCtx = $oldCtx ? $oldCtx . "\n\n---\n\n" . $newSummary : $newSummary;

$fields = [
    'summary' => $parsed['summary'] ?? '',
    'tone' => $parsed['tone'] ?? '',
    'content_preferences' => $parsed['content_preferences'] ?? '',
    'industry_context' => $parsed['industry_context'] ?? '',
    'keywords' => json_encode($parsed['keywords'] ?? []),
    'priorities' => json_encode($parsed['priorities'] ?? []),
    'skills' => json_encode($parsed['skills'] ?? []),
    'dos' => implode("\n", $parsed['dos'] ?? []),
    'donts' => implode("\n", $parsed['donts'] ?? []),
    'target_audience' => $parsed['target_audience'] ?? '',
    'context_file' => mb_substr($mergedCtx, 0, 6000),
    'last_analyzed' => date('Y-m-d H:i:s'),
    'analyzed_by' => 'reanalyze-script',
];

if ($existingRow) {
    $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($fields)));
    $stmt = $pdo->prepare("UPDATE client_knowledge SET {$sets}, version = version + 1 WHERE id = :id");
    $stmt->execute([...$fields, 'id' => $existingRow['id']]);
    echo "Updated existing client_knowledge row.\n";
} else {
    $fields['id'] = bin2hex(random_bytes(16));
    $fields['client_id'] = $client['id'];
    $fields['client_name'] = $client['name'];
    $fields['version'] = 1;
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
    $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
    $stmt = $pdo->prepare("INSERT INTO client_knowledge ({$cols}) VALUES ({$ph})");
    $stmt->execute($fields);
    echo "Inserted new client_knowledge row.\n";
}

echo "\nDone. context_file now " . strlen($mergedCtx) . " chars.\n";
