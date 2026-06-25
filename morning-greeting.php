<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$members = $pdo->query("
    SELECT name, whatsapp_number FROM team_members
    WHERE status = 'active' AND whatsapp_number IS NOT NULL AND whatsapp_number != ''
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($members as $m) {
    $tasks = $pdo->prepare("
        SELECT title, stage, scheduled_date FROM posts
        WHERE assigned_to = :n AND stage NOT IN ('published','cancelled')
        ORDER BY scheduled_date ASC LIMIT 10
    ");
    $tasks->execute([':n' => $m['name']]);
    $rows = $tasks->fetchAll(PDO::FETCH_ASSOC);
    $list = $rows
        ? implode("\n", array_map(fn($r) => "- {$r['title']} ({$r['stage']})", $rows))
        : "Nothing on your plate right now — clear day!";

    $firstName = explode(' ', trim($m['name']))[0];

    $system = "You are Pro, the friendly AI assistant inside SocialFlow. Write a short, warm good-morning "
            . "WhatsApp message to a team member. Greet them by first name, mention what they have today "
            . "based on the task list given, and end by reminding them you're always here to help. Keep it "
            . "to 3-4 sentences, casual and friendly, at most one emoji, no markdown.";
    $userMsg = "Team member: {$firstName}\nToday's open tasks:\n{$list}";

    [$status, $data] = callClaude([
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 300,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $userMsg]],
    ]);

    $message = null;
    if ($status >= 200 && $status < 300) {
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $message = trim($block['text']);
        }
    }
    if (!$message) {
        $message = "Good morning {$firstName}! 👋 Here's what's on your plate today:\n{$list}\n\nI'm always here if you need anything!";
    }

    sendWhatsAppReply($m['whatsapp_number'], $message);
    echo "Sent to {$m['name']} ({$m['whatsapp_number']})\n";
}
