<?php
// Shared "Pro" assistant logic used by wa-webhook.php and CLI test scripts.

function identifySender(PDO $pdo, string $digits) {
    $like = '%' . substr($digits, -9) . '%';

    $stmt = $pdo->prepare("SELECT id, name, role FROM team_members WHERE status = 'active' AND REPLACE(REPLACE(REPLACE(whatsapp_number,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tasks = $pdo->prepare("SELECT title, stage FROM posts WHERE assigned_to = :n AND stage NOT IN ('published','cancelled') ORDER BY scheduled_date ASC LIMIT 5");
        $tasks->execute([':n' => $m['name']]);
        $rows = $tasks->fetchAll(PDO::FETCH_ASSOC);
        $list = $rows ? implode("\n", array_map(fn($r) => "- {$r['title']} ({$r['stage']})", $rows)) : 'No open posts assigned right now.';
        return [$m['name'], 'team:' . $m['role'], "Their open assigned posts:\n$list", $m['id']];
    }

    $stmt = $pdo->prepare("SELECT up.id, up.display_name, up.user_email FROM user_profiles up INNER JOIN team_members tm ON tm.email = up.user_email AND tm.status = 'active' WHERE REPLACE(REPLACE(REPLACE(up.whatsapp_number,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [$m['display_name'] ?: $m['user_email'], 'team:member', 'No additional context loaded for this profile.'];
    }

    $stmt = $pdo->prepare("SELECT id, name FROM clients WHERE status = 'active' AND REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pending = $pdo->prepare("SELECT title FROM posts WHERE client_id = :c AND stage = 'client_review' LIMIT 5");
        $pending->execute([':c' => $m['id']]);
        $rows = $pending->fetchAll(PDO::FETCH_ASSOC);
        $list = $rows ? implode("\n", array_map(fn($r) => "- {$r['title']}", $rows)) : 'Nothing waiting on their approval right now.';
        return [$m['name'], 'client', "Posts pending their approval:\n$list"];
    }

    $stmt = $pdo->prepare("SELECT id, name, client_name FROM client_users WHERE status = 'active' AND REPLACE(REPLACE(REPLACE(mobile,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [$m['name'], 'client', "They are a client-portal user for {$m['client_name']}."];
    }

    return [null, null, null, null];
}

function proTools() {
    return [
        [
            'name' => 'list_clients',
            'description' => 'List the agency\'s clients (name, industry, status).',
            'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name' => 'list_team_members',
            'description' => 'List/search the agency\'s team members (name, role, department, status). Use this whenever asked about a colleague by name, who works at the agency, or who holds a given role.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Partial name match, e.g. "Monay" to find "Monay Khalid"'],
                    'role' => ['type' => 'string', 'description' => 'Exact role filter, e.g. admin, account_manager, content_creator, graphic_designer, accountant'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'search_tasks',
            'description' => 'Search posts/tasks. All filters are optional and combinable: query matches the task title, stage filters by exact pipeline stage, client_name matches the client (partial match ok), assigned_to matches the exact team member name. Returns up to 15 results.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query'       => ['type' => 'string', 'description' => 'Keyword to match in the task title'],
                    'stage'       => ['type' => 'string', 'description' => 'Exact pipeline stage, e.g. planning, in_progress, internal_review, client_review, scheduled, published, cancelled'],
                    'client_name' => ['type' => 'string', 'description' => 'Partial client name match'],
                    'assigned_to' => ['type' => 'string', 'description' => 'Exact name of the team member the task is assigned to'],
                ],
                'required' => [],
            ],
        ],
    ];
}

function runProTool(PDO $pdo, string $name, array $input, string $senderRole = '', ?string $senderId = null, ?string $senderName = null) {
    $isAdmin = $senderRole === 'team:admin';
    $isAM    = $senderRole === 'team:account_manager';

    if ($name === 'list_clients') {
        if ($isAdmin) {
            $stmt = $pdo->query("SELECT name, industry, status FROM clients ORDER BY name ASC LIMIT 100");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($isAM) {
            $stmt = $pdo->prepare("SELECT name, industry, status FROM clients WHERE account_manager_id = :id ORDER BY name ASC LIMIT 100");
            $stmt->execute([':id' => $senderId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['error' => 'You do not have permission to list agency clients. Ask about your own assigned tasks instead.'];
    }
    if ($name === 'list_team_members') {
        $sql = "SELECT name, role, department, status FROM team_members WHERE status = 'active'";
        $params = [];
        if (!empty($input['name'])) { $sql .= " AND name LIKE :n"; $params[':n'] = '%' . $input['name'] . '%'; }
        if (!empty($input['role'])) { $sql .= " AND role = :r"; $params[':r'] = $input['role']; }
        $sql .= " ORDER BY name ASC LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($name === 'search_tasks') {
        $sql = "SELECT title, stage, scheduled_date, client_name FROM posts WHERE 1=1";
        $params = [];
        if (!empty($input['query']))       { $sql .= " AND title LIKE :q";       $params[':q'] = '%' . $input['query'] . '%'; }
        if (!empty($input['stage']))       { $sql .= " AND stage = :s";          $params[':s'] = $input['stage']; }
        if (!empty($input['client_name'])) { $sql .= " AND client_name LIKE :c"; $params[':c'] = '%' . $input['client_name'] . '%'; }

        if ($isAdmin) {
            if (!empty($input['assigned_to'])) { $sql .= " AND assigned_to = :a"; $params[':a'] = $input['assigned_to']; }
        } elseif ($isAM) {
            $sql .= " AND client_id IN (SELECT id FROM clients WHERE account_manager_id = :mid)";
            $params[':mid'] = $senderId;
        } else {
            $sql .= " AND assigned_to = :a"; $params[':a'] = $senderName;
        }

        $sql .= " ORDER BY scheduled_date ASC LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['error' => 'Unknown tool: ' . $name];
}

function callClaude(array $payload) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
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
    $err = curl_error($ch);
    curl_close($ch);
    return [$status, json_decode($res, true), $res];
}

function askPro(PDO $pdo, $senderName, $senderRole, $contextBlock, $userText, $senderId = null) {
    $isTeam  = $senderName && str_starts_with((string)$senderRole, 'team:');
    $isAdmin = $senderRole === 'team:admin';
    $isAM    = $senderRole === 'team:account_manager';

    if (!$senderName) {
        $system = "You are Pro, the AI assistant for SocialFlow (a social media agency management app). "
                . "This WhatsApp number isn't linked to any SocialFlow account yet. Politely tell the sender "
                . "to ask their SocialFlow admin to add their WhatsApp number to their profile, in one short message.";
        $tools = [];
    } else {
        $system = "You are Pro, the AI assistant built into SocialFlow. You are replying over WhatsApp to "
                . "{$senderName} (role: {$senderRole}). Keep replies short — a few sentences max, WhatsApp-style, "
                . "no markdown headers. You can see this context about them:\n\n{$contextBlock}\n\n"
                . "Only answer questions related to their SocialFlow work (clients, tasks, the agency). Politely "
                . "decline anything unrelated (general trivia, unrelated requests) and steer back to SocialFlow. "
                . "If asked to take an action you can't perform over WhatsApp (e.g. editing a post), tell them "
                . "to open the SocialFlow app to do it, but still answer their question as best you can here.";

        if ($isAdmin) {
            $tools = proTools();
            $system .= "\n\nAs admin, you have full tools to look up any client and search any task across the "
                     . "whole agency — use them whenever the question needs current data instead of guessing.";
        } elseif ($isAM) {
            $tools = proTools();
            $system .= "\n\nYou have tools to look up clients and tasks, but only for the clients you personally "
                     . "manage as account manager — results outside your own clients will not be shown.";
        } elseif ($isTeam) {
            $tools = array_values(array_filter(proTools(), fn($t) => $t['name'] !== 'list_clients'));
            $system .= "\n\nYou have a tool to search tasks, but only your own assigned tasks — you do not have "
                     . "permission to browse the full client list or other people's tasks.";
        } else {
            $tools = [];
        }
    }

    $messages = [['role' => 'user', 'content' => $userText]];

    for ($turn = 0; $turn < 4; $turn++) {
        $payload = ['model' => 'claude-sonnet-4-6', 'max_tokens' => 500, 'system' => $system, 'messages' => $messages];
        if ($tools) $payload['tools'] = $tools;

        [$status, $data, $raw] = callClaude($payload);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $content = $data['content'] ?? [];
        $stop = $data['stop_reason'] ?? '';

        if ($stop !== 'tool_use') {
            $textOut = '';
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') $textOut .= $block['text'];
            }
            return trim($textOut) ?: null;
        }

        foreach ($content as &$block) {
            if (($block['type'] ?? '') === 'tool_use' && empty($block['input'])) {
                $block['input'] = new stdClass();
            }
        }
        unset($block);
        $messages[] = ['role' => 'assistant', 'content' => $content];
        $toolResults = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') !== 'tool_use') continue;
            $result = runProTool($pdo, $block['name'], (array)($block['input'] ?? []), (string)$senderRole, $senderId, $senderName);
            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $block['id'],
                'content' => json_encode($result),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }

    return "Sorry, that took too long to look up — try asking again, or check the app directly.";
}

function sendWhatsAppReply($to, $body) {
    if (!defined('WA_PHONE_ID') || !defined('WA_ACCESS_TOKEN') || !WA_PHONE_ID || !WA_ACCESS_TOKEN) return;
    $to = preg_replace('/[\s\-\(\)]+/', '', $to);
    if (!str_starts_with($to, '+')) $to = '+' . $to;
    $to = '+' . preg_replace('/\D/', '', $to);
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
