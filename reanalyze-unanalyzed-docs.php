<?php
// One-off — re-runs AI analysis for any client_documents row still marked
// analyzed=0, now that max_tokens is raised (the same truncation bug that
// hit the manual Generate button and daily cron also silently broke the
// live upload-analysis path — this covers docs uploaded before that fix,
// e.g. TSC's "Chatgpt 1").
require_once __DIR__ . '/config.php';
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function callClaudeDirect(string $prompt, int $maxTokens = 2000): array {
    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['model' => 'claude-sonnet-4-6', 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'Content-Type: application/json'],
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) return [null, "HTTP {$status}: " . substr((string)$res, 0, 300)];
    $data = json_decode($res, true);
    $raw = '';
    foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $raw .= $block['text']; }
    if (!preg_match('/\{[\s\S]*\}/', $raw, $m)) return [null, "No JSON in response: " . mb_substr($raw, 0, 300)];
    $parsed = json_decode($m[0], true);
    return $parsed ? [$parsed, null] : [null, "Failed to decode JSON"];
}

$docs = $pdo->query("SELECT id, name, doc_type, client_id, client_name, content FROM client_documents WHERE analyzed = 0")->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($docs) . " unanalyzed document(s).\n\n";
if (!$docs) exit;

$existingStmt = $pdo->prepare("SELECT id, version, context_file FROM client_knowledge WHERE client_id = ?");
$markAnalyzed = $pdo->prepare("UPDATE client_documents SET analyzed = 1 WHERE id = ?");

foreach ($docs as $doc) {
    echo "=== {$doc['name']} ({$doc['client_name']}) ===\n";
    $isChatGPT = $doc['doc_type'] === 'chatgpt';
    $content = $doc['content'] ?? '';
    $prompt = $isChatGPT
        ? "You are analyzing a ChatGPT conversation that contains discussions about a client's brand and content strategy.\n\n"
            . "Client: {$doc['client_name']}\nChatGPT Conversation:\n" . mb_substr($content, 0, 700000) . "\n\n"
            . "Extract ONLY the useful client brief information from this conversation. Ignore generic ChatGPT responses. Focus on what was discussed about the client's brand, goals, audience, and content preferences.\n\n"
            . "Return ONLY valid JSON (no markdown, no explanation):\n"
            . '{"summary":"2-3 sentences about this client based on the chat","tone":"brand voice/communication style extracted from chat","content_preferences":"what type of content they want","industry_context":"their industry and market","keywords":["kw1","kw2","kw3"],"priorities":["priority1","priority2"],"skills":[{"name":"Skill","confidence":80,"category":"Content"}],"dos":["do this","and this"],"donts":["avoid this","never this"],"target_audience":"who they are targeting"}'
        : "Analyze these client documents and extract a knowledge profile for: {$doc['client_name']}\n\nDOCUMENTS:\n" . mb_substr($content, 0, 700000)
            . "\n\nReturn ONLY valid JSON (no markdown, no explanation):\n{\"summary\":\"2-3 sentences about this client\",\"tone\":\"communication style\",\"content_preferences\":\"what they like\",\"industry_context\":\"their industry\",\"keywords\":[\"kw1\",\"kw2\"],\"priorities\":[\"p1\",\"p2\"],\"skills\":[{\"name\":\"Skill\",\"confidence\":85,\"category\":\"Content\"}]}";

    [$parsed, $err] = callClaudeDirect($prompt);
    if (!$parsed) { echo "  Failed: {$err}\n"; continue; }

    $existingStmt->execute([$doc['client_id']]);
    $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
    $newSummary = "## " . ($isChatGPT ? "ChatGPT Import" : $doc['name']) . "\n" . ($parsed['summary'] ?? '')
        . "\n\n**Tone:** " . ($parsed['tone'] ?? '')
        . "\n**Keywords:** " . implode(', ', array_slice($parsed['keywords'] ?? [], 0, 6))
        . "\n**Priorities:** " . implode(', ', array_slice($parsed['priorities'] ?? [], 0, 3))
        . (!empty($parsed['dos']) ? "\n**Do's:** " . implode(', ', array_slice($parsed['dos'], 0, 2)) : '')
        . (!empty($parsed['donts']) ? "\n**Don'ts:** " . implode(', ', array_slice($parsed['donts'], 0, 2)) : '')
        . "\n**Audience:** " . ($parsed['target_audience'] ?? '');
    $oldCtx = $existingRow['context_file'] ?? '';
    $mergedCtx = $oldCtx ? $oldCtx . "\n\n---\n\n" . $newSummary : $newSummary;

    $fields = [
        'summary' => $parsed['summary'] ?? '', 'tone' => $parsed['tone'] ?? '',
        'content_preferences' => $parsed['content_preferences'] ?? '', 'industry_context' => $parsed['industry_context'] ?? '',
        'keywords' => json_encode($parsed['keywords'] ?? []), 'priorities' => json_encode($parsed['priorities'] ?? []),
        'skills' => json_encode($parsed['skills'] ?? []),
        'dos' => implode("\n", $parsed['dos'] ?? []), 'donts' => implode("\n", $parsed['donts'] ?? []),
        'target_audience' => $parsed['target_audience'] ?? '',
        'context_file' => mb_substr($mergedCtx, 0, 100000),
        'last_analyzed' => date('Y-m-d H:i:s'), 'analyzed_by' => 'reanalyze-unanalyzed-script',
    ];
    if ($existingRow) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($fields)));
        $pdo->prepare("UPDATE client_knowledge SET {$sets}, version = version + 1 WHERE id = :id")->execute([...$fields, 'id' => $existingRow['id']]);
    } else {
        $fields['id'] = bin2hex(random_bytes(16)); $fields['client_id'] = $doc['client_id']; $fields['client_name'] = $doc['client_name']; $fields['version'] = 1;
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
        $ph = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $pdo->prepare("INSERT INTO client_knowledge ({$cols}) VALUES ({$ph})")->execute($fields);
    }
    $markAnalyzed->execute([$doc['id']]);
    echo "  Analyzed and saved.\n";
    usleep(500000);
}
echo "\nDone.\n";
