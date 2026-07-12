<?php
// Shared "Pro" assistant logic used by wa-webhook.php and CLI test scripts.

// Downloads a WhatsApp media object (voice note, etc) by its media id and
// returns [bytes, mime_type] — or [null, null] on any failure. Two-step
// Graph API dance: media id -> signed CDN URL -> raw bytes, both requests
// authenticated with the WhatsApp access token.
function downloadWhatsAppMedia(string $mediaId) {
    if (!defined('WA_ACCESS_TOKEN') || !WA_ACCESS_TOKEN) return [null, null];
    $ch = curl_init("https://graph.facebook.com/v19.0/{$mediaId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_ACCESS_TOKEN],
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $meta = json_decode($res, true);
    $url = $meta['url'] ?? null;
    $mime = $meta['mime_type'] ?? 'audio/ogg';
    if (!$url) return [null, null];

    $ch2 = curl_init($url);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_ACCESS_TOKEN],
        CURLOPT_TIMEOUT => 30,
    ]);
    $bytes = curl_exec($ch2);
    curl_close($ch2);
    return $bytes ? [$bytes, $mime] : [null, null];
}

// Transcribes audio bytes via OpenAI's Whisper endpoint. Requires
// OPENAI_API_KEY in config.php — returns null (not an error) if that's not
// configured, so the caller can tell the user voice notes aren't set up yet
// instead of throwing.
function transcribeAudio(string $bytes, string $mimeType) {
    if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) return null;
    $extMap = ['ogg' => 'ogg', 'mp4' => 'm4a', 'webm' => 'webm', 'mpeg' => 'mp3', 'mp3' => 'mp3', 'wav' => 'wav'];
    $ext = 'oga';
    foreach ($extMap as $needle => $mapped) { if (str_contains($mimeType, $needle)) { $ext = $mapped; break; } }
    $tmpFile = tempnam(sys_get_temp_dir(), 'wa_voice_') . '.' . $ext;
    file_put_contents($tmpFile, $bytes);

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($tmpFile, $mimeType, 'voice.' . $ext),
            'model' => 'whisper-1',
        ],
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);

    if ($status < 200 || $status >= 300) return null;
    $data = json_decode($res, true);
    return trim($data['text'] ?? '') ?: null;
}

function identifySender(PDO $pdo, string $digits) {
    $like = '%' . substr($digits, -9) . '%';

    $stmt = $pdo->prepare("SELECT id, name, role FROM team_members WHERE status = 'active' AND REPLACE(REPLACE(REPLACE(whatsapp_number,'+',''),' ',''),'-','') LIKE :p LIMIT 1");
    $stmt->execute([':p' => $like]);
    if ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tasks = $pdo->prepare("SELECT title, stage FROM posts WHERE assigned_to = :n AND stage NOT IN ('published','cancelled') ORDER BY scheduled_date ASC LIMIT 5");
        $tasks->execute([':n' => $m['name']]);
        $rows = $tasks->fetchAll(PDO::FETCH_ASSOC);
        $list = $rows ? implode("\n", array_map(fn($r) => "- {$r['title']} ({$r['stage']})", $rows)) : 'No open posts assigned right now.';

        // If this person manages others, surface any leave/WFH requests waiting
        // on their approval so Claude can act on "approve Sara's request" without
        // a separate lookup round-trip — decide_pending_request matches on the
        // short id (last 8 chars of the UUID) shown here.
        $pending = $pdo->prepare("SELECT id, member_name, type, start_date, end_date, days, reason FROM leave_requests WHERE manager_id = :mid AND status = 'pending' ORDER BY created_at ASC LIMIT 10");
        $pending->execute([':mid' => $m['id']]);
        $pendingRows = $pending->fetchAll(PDO::FETCH_ASSOC);
        $approvalsBlock = '';
        if ($pendingRows) {
            $lines = array_map(function($r) {
                $shortId = substr($r['id'], -8);
                $range = $r['start_date'] === $r['end_date'] ? $r['start_date'] : "{$r['start_date']} to {$r['end_date']}";
                return "- [{$shortId}] {$r['member_name']}: {$r['type']} on {$range} ({$r['days']} day(s))" . ($r['reason'] ? " — \"{$r['reason']}\"" : '');
            }, $pendingRows);
            $approvalsBlock = "\n\nPending leave/WFH requests waiting on YOUR approval:\n" . implode("\n", $lines);
        }

        return [$m['name'], 'team:' . $m['role'], "Their open assigned posts:\n$list" . $approvalsBlock, $m['id']];
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

// Finance tools — admin/accountant only. Reads/writes the same `expenses`
// table the Finance page in the app uses (type='in'|'out'), so anything Pro
// adds here shows up in that page's ledger immediately, and vice versa.
function financeTools() {
    return [
        [
            'name' => 'get_finance_summary',
            'description' => 'Get financial totals (money in, money out, balance) and a breakdown by category, optionally filtered by date range and/or category. Use this for any question about income, expenses, spending, or balance.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD — omit for all time'],
                    'end_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD — omit for all time (defaults to today if start_date given)'],
                    'category'   => ['type' => 'string', 'description' => 'Filter to one category, e.g. salaries, tools, rent, ads, freelancers, other, client_payment, other_income — omit for all categories'],
                    'type'       => ['type' => 'string', 'enum' => ['in', 'out'], 'description' => 'Filter to only income or only expenses — omit for both'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'search_transactions',
            'description' => 'Search/list individual finance transactions (income or expenses) by keyword, category, or date range. Use this when asked to find/show a specific transaction or recent transactions, not just totals. Each result includes a short_id — use it with edit_transaction/delete_transaction.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query'      => ['type' => 'string', 'description' => 'Keyword to match in the description'],
                    'category'   => ['type' => 'string'],
                    'type'       => ['type' => 'string', 'enum' => ['in', 'out']],
                    'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'add_transaction',
            'description' => 'Record a new income or expense transaction. Before calling this, make sure you have all required fields from the user — if anything is missing or ambiguous (especially amount or whether it is money in or out), ASK the user instead of guessing. Once saved, confirm back to the user exactly what was recorded (type, amount, category, description, date).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type'        => ['type' => 'string', 'enum' => ['in', 'out'], 'description' => '"in" = income/money received, "out" = expense/money spent'],
                    'category'    => ['type' => 'string', 'description' => 'For expenses (type=out): salaries, tools, rent (rent & utilities incl. electricity), ads, freelancers, general (general expenses), office_supplies (coffee, water, snacks, pantry items), debt_repayment, partner_mostafa (Mostafa\'s partner withdrawal), partner_radwa (Radwa\'s partner withdrawal), or other. For income (type=in): client_payment or other_income.'],
                    'description' => ['type' => 'string', 'description' => 'Short description, e.g. "April office rent" or "Bank transfer — Acme Co."'],
                    'amount'      => ['type' => 'number'],
                    'currency'    => ['type' => 'string', 'description' => 'Defaults to EGP'],
                    'date'        => ['type' => 'string', 'description' => 'YYYY-MM-DD — defaults to today if omitted'],
                ],
                'required' => ['type', 'category', 'description', 'amount'],
            ],
        ],
        [
            'name' => 'edit_transaction',
            'description' => 'Edit an existing income or expense transaction. Find its short_id first with search_transactions if you do not already have it. Only include the fields being changed. Confirm back to the user what was changed once saved.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'short_id'    => ['type' => 'string', 'description' => 'The short id from search_transactions, e.g. "a1b2c3d4"'],
                    'type'        => ['type' => 'string', 'enum' => ['in', 'out']],
                    'category'    => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'amount'      => ['type' => 'number'],
                    'currency'    => ['type' => 'string'],
                    'date'        => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ],
                'required' => ['short_id'],
            ],
        ],
        [
            'name' => 'delete_transaction',
            'description' => 'Permanently delete a transaction. Admin only. Find its short_id first with search_transactions and confirm with the user before deleting, since this cannot be undone.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'short_id' => ['type' => 'string', 'description' => 'The short id from search_transactions, e.g. "a1b2c3d4"'],
                ],
                'required' => ['short_id'],
            ],
        ],
    ];
}

function runFinanceTool(PDO $pdo, string $name, array $input, ?string $senderName) {
    $expenseCats = ['salaries', 'tools', 'rent', 'ads', 'freelancers', 'general', 'office_supplies', 'debt_repayment', 'partner_mostafa', 'partner_radwa', 'other'];
    $incomeCats  = ['client_payment', 'other_income'];

    if ($name === 'get_finance_summary') {
        $sql = "SELECT type, category, SUM(amount) AS total, COUNT(*) AS cnt FROM expenses WHERE 1=1";
        $params = [];
        if (!empty($input['start_date'])) { $sql .= " AND date >= :sd"; $params[':sd'] = $input['start_date']; }
        if (!empty($input['end_date']))   { $sql .= " AND date <= :ed"; $params[':ed'] = $input['end_date']; }
        elseif (!empty($input['start_date'])) { $sql .= " AND date <= :ed"; $params[':ed'] = date('Y-m-d'); }
        if (!empty($input['category'])) { $sql .= " AND category = :c"; $params[':c'] = $input['category']; }
        if (!empty($input['type']))     { $sql .= " AND type = :t"; $params[':t'] = $input['type']; }
        $sql .= " GROUP BY type, category";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalIn = 0; $totalOut = 0; $byCategory = [];
        foreach ($rows as $r) {
            if ($r['type'] === 'in') $totalIn += (float)$r['total'];
            else $totalOut += (float)$r['total'];
            $byCategory[] = ['type' => $r['type'], 'category' => $r['category'], 'total' => (float)$r['total'], 'count' => (int)$r['cnt']];
        }
        return [
            'total_in' => round($totalIn, 2),
            'total_out' => round($totalOut, 2),
            'balance' => round($totalIn - $totalOut, 2),
            'currency' => 'EGP',
            'by_category' => $byCategory,
        ];
    }

    if ($name === 'search_transactions') {
        $sql = "SELECT id, type, category, description, amount, currency, date FROM expenses WHERE 1=1";
        $params = [];
        if (!empty($input['query']))      { $sql .= " AND description LIKE :q"; $params[':q'] = '%' . $input['query'] . '%'; }
        if (!empty($input['category']))   { $sql .= " AND category = :c"; $params[':c'] = $input['category']; }
        if (!empty($input['type']))       { $sql .= " AND type = :t"; $params[':t'] = $input['type']; }
        if (!empty($input['start_date'])) { $sql .= " AND date >= :sd"; $params[':sd'] = $input['start_date']; }
        if (!empty($input['end_date']))   { $sql .= " AND date <= :ed"; $params[':ed'] = $input['end_date']; }
        $sql .= " ORDER BY date DESC LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function($r) {
            $r['short_id'] = substr($r['id'], -8);
            unset($r['id']);
            return $r;
        }, $rows);
    }

    if ($name === 'edit_transaction') {
        $shortId = trim($input['short_id'] ?? '');
        if (!$shortId) return ['error' => 'Missing short_id — use search_transactions to find it first.'];
        $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id LIKE :sid LIMIT 1");
        $stmt->execute([':sid' => '%' . $shortId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['error' => 'No transaction found with that short_id.'];

        $sets = []; $bind = [':id' => $row['id']];
        foreach (['type', 'category', 'description', 'amount', 'currency', 'date'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                $sets[] = "$field = :$field";
                $bind[":$field"] = $input[$field];
            }
        }
        if (!$sets) return ['error' => 'No fields to update were provided.'];
        $pdo->prepare("UPDATE expenses SET " . implode(', ', $sets) . " WHERE id = :id")->execute($bind);

        $stmt = $pdo->prepare("SELECT type, category, description, amount, currency, date FROM expenses WHERE id = :id");
        $stmt->execute([':id' => $row['id']]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['ok' => true, 'message' => 'Transaction updated.'] + $updated;
    }

    if ($name === 'delete_transaction') {
        $shortId = trim($input['short_id'] ?? '');
        if (!$shortId) return ['error' => 'Missing short_id — use search_transactions to find it first.'];
        $stmt = $pdo->prepare("SELECT id, description, amount, type FROM expenses WHERE id LIKE :sid LIMIT 1");
        $stmt->execute([':sid' => '%' . $shortId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['error' => 'No transaction found with that short_id.'];
        $pdo->prepare("DELETE FROM expenses WHERE id = :id")->execute([':id' => $row['id']]);
        return ['ok' => true, 'message' => "Deleted: {$row['description']} ({$row['amount']})."];
    }

    if ($name === 'add_transaction') {
        $type = $input['type'] ?? '';
        $category = $input['category'] ?? '';
        $description = trim($input['description'] ?? '');
        $amount = $input['amount'] ?? null;
        if (!in_array($type, ['in', 'out'], true) || !$description || !is_numeric($amount) || (float)$amount <= 0) {
            return ['error' => 'Missing or invalid type/description/amount — ask the user for whatever is missing.'];
        }
        $validCats = $type === 'out' ? $expenseCats : $incomeCats;
        if (!in_array($category, $validCats, true)) $category = 'other';
        $currency = $input['currency'] ?? 'EGP';
        $date = !empty($input['date']) ? $input['date'] : date('Y-m-d');

        $id = generateProUuid();
        $ins = $pdo->prepare("INSERT INTO expenses (id, type, category, description, amount, currency, date, created_by) VALUES (:id, :type, :cat, :desc, :amt, :cur, :date, :by)");
        $ins->execute([
            ':id' => $id, ':type' => $type, ':cat' => $category, ':desc' => $description,
            ':amt' => $amount, ':cur' => $currency, ':date' => $date, ':by' => $senderName,
        ]);
        return [
            'ok' => true, 'type' => $type, 'category' => $category, 'description' => $description,
            'amount' => (float)$amount, 'currency' => $currency, 'date' => $date,
            'message' => 'Saved to the Finance page.',
        ];
    }

    return ['error' => 'Unknown tool: ' . $name];
}

// Self-service HR tools — available to every team member regardless of role,
// since these only ever touch the asker's own record (or, for approvals, a
// request explicitly addressed to them as manager_id).
function hrTools() {
    return [
        [
            'name' => 'request_time_off',
            'description' => 'Request vacation (paid day off) or WFH (work from home) for yourself. Creates a pending request and immediately notifies your manager over WhatsApp for approval — tell the user you have done this, you cannot approve your own request.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'leave_type'  => ['type' => 'string', 'enum' => ['vacation', 'wfh']],
                    'start_date'  => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end_date'    => ['type' => 'string', 'description' => 'YYYY-MM-DD — same as start_date for a single day'],
                    'reason'      => ['type' => 'string', 'description' => 'Short reason, optional'],
                ],
                'required' => ['leave_type', 'start_date'],
            ],
        ],
        [
            'name' => 'decide_pending_request',
            'description' => 'Approve or reject a leave/WFH request that is waiting on YOUR approval (see the "Pending leave/WFH requests" list in your context above, if any). Only requests addressed to you as manager can be decided this way.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'request_id' => ['type' => 'string', 'description' => 'The short id shown in brackets in your pending approvals list, e.g. "a1b2c3d4"'],
                    'decision'   => ['type' => 'string', 'enum' => ['approve', 'reject']],
                    'note'       => ['type' => 'string', 'description' => 'Optional short note, e.g. reason for rejecting'],
                ],
                'required' => ['request_id', 'decision'],
            ],
        ],
        [
            'name' => 'get_my_hr_info',
            'description' => 'Get YOUR OWN salary, vacation/WFH day credits (total/used/remaining), manager, and department. Always scoped to the asker only — never returns a colleague\'s data.',
            'input_schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name' => 'save_contact_report',
            'description' => 'Save a structured contact/call report from a client interaction — typically after the user sends a voice note debriefing a call or meeting. Extract the client name, a short summary, key discussion points, and any action items from what they told you, then save it here so it shows up on that client\'s profile in the app. Confirm to the user once saved.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'client_name'  => ['type' => 'string', 'description' => 'The client this report is about — best-effort match, does not need to be exact'],
                    'summary'      => ['type' => 'string', 'description' => '2-3 sentence summary of the interaction'],
                    'key_points'   => ['type' => 'string', 'description' => 'Bullet-style key discussion points, one per line'],
                    'action_items' => ['type' => 'string', 'description' => 'Bullet-style follow-up actions, one per line — empty if none'],
                ],
                'required' => ['summary'],
            ],
        ],
    ];
}

function generateProUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function runHrTool(PDO $pdo, string $name, array $input, ?string $senderId, ?string $senderName) {
    if (!$senderId) return ['error' => 'Could not identify your team member record — ask your admin to add your WhatsApp number to your profile.'];

    if ($name === 'get_my_hr_info') {
        $stmt = $pdo->prepare("SELECT tm.department, tm.salary, tm.vacation_days_total, tm.vacation_days_used, tm.wfh_days_total, tm.wfh_days_used, mgr.name AS manager_name FROM team_members tm LEFT JOIN team_members mgr ON mgr.id = tm.manager_id WHERE tm.id = :id");
        $stmt->execute([':id' => $senderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['error' => 'Profile not found.'];
        return [
            'department' => $row['department'],
            'manager' => $row['manager_name'],
            'salary' => $row['salary'],
            'vacation_days_remaining' => (float)$row['vacation_days_total'] - (float)$row['vacation_days_used'],
            'vacation_days_total' => (float)$row['vacation_days_total'],
            'wfh_days_remaining' => (float)$row['wfh_days_total'] - (float)$row['wfh_days_used'],
            'wfh_days_total' => (float)$row['wfh_days_total'],
        ];
    }

    if ($name === 'request_time_off') {
        $type = $input['leave_type'] ?? '';
        $start = trim($input['start_date'] ?? '');
        $end = trim($input['end_date'] ?? '') ?: $start;
        if (!in_array($type, ['vacation', 'wfh'], true) || !$start) {
            return ['error' => 'Missing or invalid leave_type/start_date.'];
        }
        $days = max(1, (strtotime($end) - strtotime($start)) / 86400 + 1);

        $mgrStmt = $pdo->prepare("SELECT tm.manager_id, mgr.name AS manager_name, mgr.whatsapp_number AS manager_phone FROM team_members tm LEFT JOIN team_members mgr ON mgr.id = tm.manager_id WHERE tm.id = :id");
        $mgrStmt->execute([':id' => $senderId]);
        $mgr = $mgrStmt->fetch(PDO::FETCH_ASSOC);
        if (!$mgr || !$mgr['manager_id']) {
            return ['error' => 'You do not have a manager assigned in your profile — ask your admin to set one before requesting time off.'];
        }

        $reqId = generateProUuid();
        $ins = $pdo->prepare("INSERT INTO leave_requests (id, team_member_id, member_name, type, start_date, end_date, days, reason, status, manager_id, manager_name, source) VALUES (:id, :tid, :tname, :type, :start, :end, :days, :reason, 'pending', :mid, :mname, 'whatsapp')");
        $ins->execute([
            ':id' => $reqId, ':tid' => $senderId, ':tname' => $senderName, ':type' => $type,
            ':start' => $start, ':end' => $end, ':days' => $days, ':reason' => $input['reason'] ?? null,
            ':mid' => $mgr['manager_id'], ':mname' => $mgr['manager_name'],
        ]);

        if (!empty($mgr['manager_phone'])) {
            $shortId = substr($reqId, -8);
            $range = $start === $end ? $start : "{$start} to {$end}";
            $label = $type === 'vacation' ? 'vacation' : 'work-from-home';
            $reasonLine = !empty($input['reason']) ? "\nReason: {$input['reason']}" : '';
            sendWhatsAppReply($mgr['manager_phone'],
                "🗓️ {$senderName} requested {$label} for {$range} ({$days} day(s)).{$reasonLine}\n\n" .
                "Reply here to approve or reject it — just tell Pro, e.g. \"approve {$shortId}\" or \"reject {$shortId}\"."
            );
        }

        return ['ok' => true, 'request_id' => substr($reqId, -8), 'message' => 'Request submitted and your manager has been notified over WhatsApp.'];
    }

    if ($name === 'decide_pending_request') {
        $shortId = trim($input['request_id'] ?? '');
        $decision = $input['decision'] ?? '';
        if (!$shortId || !in_array($decision, ['approve', 'reject'], true)) {
            return ['error' => 'Missing or invalid request_id/decision.'];
        }
        $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE manager_id = :mid AND status = 'pending' AND id LIKE :sid LIMIT 1");
        $stmt->execute([':mid' => $senderId, ':sid' => '%' . $shortId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) return ['error' => 'No pending request with that id is waiting on your approval.'];

        $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
        $upd = $pdo->prepare("UPDATE leave_requests SET status = :s, decision_note = :note, decided_at = NOW() WHERE id = :id");
        $upd->execute([':s' => $newStatus, ':note' => $input['note'] ?? null, ':id' => $req['id']]);

        if ($newStatus === 'approved') {
            $col = $req['type'] === 'vacation' ? 'vacation_days_used' : 'wfh_days_used';
            $pdo->prepare("UPDATE team_members SET {$col} = {$col} + :days WHERE id = :id")
                ->execute([':days' => $req['days'], ':id' => $req['team_member_id']]);
        }

        $requester = $pdo->prepare("SELECT whatsapp_number FROM team_members WHERE id = :id");
        $requester->execute([':id' => $req['team_member_id']]);
        $phone = $requester->fetchColumn();
        if ($phone) {
            $verb = $newStatus === 'approved' ? 'approved ✅' : 'rejected ❌';
            $label = $req['type'] === 'vacation' ? 'vacation' : 'WFH';
            $noteLine = !empty($input['note']) ? "\nNote from {$senderName}: {$input['note']}" : '';
            sendWhatsAppReply($phone, "Your {$label} request for {$req['start_date']} has been {$verb} by {$senderName}.{$noteLine}");
        }

        return ['ok' => true, 'status' => $newStatus, 'message' => "Request {$newStatus}, and {$req['member_name']} has been notified over WhatsApp."];
    }

    if ($name === 'save_contact_report') {
        $clientId = null; $clientName = $input['client_name'] ?? null;
        if ($clientName) {
            $c = $pdo->prepare("SELECT id, name FROM clients WHERE name LIKE :n LIMIT 1");
            $c->execute([':n' => '%' . $clientName . '%']);
            if ($row = $c->fetch(PDO::FETCH_ASSOC)) { $clientId = $row['id']; $clientName = $row['name']; }
        }
        $ins = $pdo->prepare("INSERT INTO contact_reports (id, client_id, client_name, created_by_id, created_by_name, transcript, summary, key_points, action_items, channel) VALUES (:id, :cid, :cname, :bid, :bname, :transcript, :summary, :points, :actions, 'whatsapp')");
        $reportId = generateProUuid();
        $ins->execute([
            ':id' => $reportId, ':cid' => $clientId, ':cname' => $clientName,
            ':bid' => $senderId, ':bname' => $senderName,
            ':transcript' => $input['_raw_transcript'] ?? null,
            ':summary' => $input['summary'] ?? '', ':points' => $input['key_points'] ?? null, ':actions' => $input['action_items'] ?? null,
        ]);
        return ['ok' => true, 'message' => 'Contact report saved' . ($clientName ? " for {$clientName}" : '') . '.'];
    }

    return ['error' => 'Unknown tool: ' . $name];
}

function runProTool(PDO $pdo, string $name, array $input, string $senderRole = '', ?string $senderId = null, ?string $senderName = null) {
    if (in_array($name, ['request_time_off', 'decide_pending_request', 'get_my_hr_info'], true)) {
        return runHrTool($pdo, $name, $input, $senderId, $senderName);
    }
    if (in_array($name, ['get_finance_summary', 'search_transactions', 'add_transaction', 'edit_transaction', 'delete_transaction'], true)) {
        if ($name === 'delete_transaction' && $senderRole !== 'team:admin') {
            return ['error' => 'Only an admin can delete financial transactions.'];
        }
        if (!in_array($senderRole, ['team:admin', 'team:accountant'], true)) {
            return ['error' => 'You do not have permission to access financial data.'];
        }
        return runFinanceTool($pdo, $name, $input, $senderName);
    }
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

// Pro is otherwise stateless per incoming webhook call (each PHP-FPM request
// starts fresh) — these give it short-term memory of the last few messages in
// a WhatsApp chat, so multi-step exchanges (e.g. "add a transaction" where the
// amount and description arrive in separate messages) don't loop forever
// re-asking for info the user already gave a message or two ago.
// Both wrapped in try/catch so a missing/broken pro_messages table (e.g. the
// migration hasn't been run yet) degrades to stateless behavior instead of
// silently killing the whole reply.
function loadRecentProMessages(PDO $pdo, string $phone, int $limit = 10): array {
    try {
        $stmt = $pdo->prepare("SELECT role, content FROM pro_messages WHERE phone = :p ORDER BY created_at DESC LIMIT :lim");
        $stmt->bindValue(':p', $phone, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        return array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content']], $rows);
    } catch (Throwable $e) {
        error_log('[pro-lib] loadRecentProMessages failed: ' . $e->getMessage());
        return [];
    }
}

function saveProMessage(PDO $pdo, string $phone, string $role, string $content) {
    try {
        $stmt = $pdo->prepare("INSERT INTO pro_messages (id, phone, role, content) VALUES (:id, :phone, :role, :content)");
        $stmt->execute([':id' => generateProUuid(), ':phone' => $phone, ':role' => $role, ':content' => $content]);
    } catch (Throwable $e) {
        error_log('[pro-lib] saveProMessage failed: ' . $e->getMessage());
    }
}

function askPro(PDO $pdo, $senderName, $senderRole, $contextBlock, $userText, $senderId = null, $voiceTranscript = null, $fromPhone = null) {
    $isTeam       = $senderName && str_starts_with((string)$senderRole, 'team:');
    $isAdmin      = $senderRole === 'team:admin';
    $isAM         = $senderRole === 'team:account_manager';
    $isAccountant = $senderRole === 'team:accountant';

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
                . "to open the SocialFlow app to do it, but still answer their question as best you can here.\n\n"
                . "You can also handle HR requests: asking about their own salary/vacation/WFH credits "
                . "(get_my_hr_info), requesting vacation or work-from-home (request_time_off), and — if they "
                . "manage other people — approving or rejecting requests waiting on them (decide_pending_request, "
                . "see their pending approvals list above if any). These tools only ever touch the asker's own "
                . "record or requests explicitly addressed to them."
                . ($voiceTranscript
                    ? "\n\nThis message is a transcript of a VOICE NOTE they just sent you — it may contain "
                      . "transcription errors, use your judgement. If it sounds like a debrief of a client call/"
                      . "meeting (mentions a client and what was discussed), extract and save it with "
                      . "save_contact_report, then confirm briefly what you saved. If it's just a normal question "
                      . "instead, answer it normally like any other message."
                    : '');

        if ($isAdmin) {
            $tools = array_merge(proTools(), hrTools(), financeTools());
            $system .= "\n\nAs admin, you have full tools to look up any client and search any task across the "
                     . "whole agency — use them whenever the question needs current data instead of guessing.";
        } elseif ($isAM) {
            $tools = array_merge(proTools(), hrTools());
            $system .= "\n\nYou have tools to look up clients and tasks, but only for the clients you personally "
                     . "manage as account manager — results outside your own clients will not be shown.";
        } elseif ($isAccountant) {
            $tools = array_merge(array_values(array_filter(proTools(), fn($t) => $t['name'] !== 'list_clients')), hrTools(), financeTools());
            $system .= "\n\nYou have tools to search your own tasks and to view/add financial data (income, "
                     . "expenses, balance) — this is the same data shown on the Finance page in the app.";
        } elseif ($isTeam) {
            $tools = array_merge(array_values(array_filter(proTools(), fn($t) => $t['name'] !== 'list_clients')), hrTools());
            $system .= "\n\nYou have a tool to search tasks, but only your own assigned tasks — you do not have "
                     . "permission to browse the full client list or other people's tasks.";
        } else {
            $tools = [];
        }
        if ($isAdmin || $isAccountant) {
            $system .= "\n\nFinancial data: you can answer any question about money in, money out, balance, or "
                     . "spending by category using get_finance_summary and search_transactions — always use "
                     . "these tools rather than guessing numbers. To record a new income or expense with "
                     . "add_transaction, you need: whether it's money IN or OUT, an amount, a short description, "
                     . "and (ideally) a category. If the user's message is missing any of these or is ambiguous "
                     . "(e.g. they just say \"add 500 for coffee\" without saying in/out — assume OUT for an "
                     . "expense-sounding request, but ask if genuinely unclear), ask a short follow-up question "
                     . "instead of guessing. After successfully saving, always confirm back to the user exactly "
                     . "what was recorded (amount, type, category, description, date) in one short line.\n\n"
                     . "To change something already recorded, use search_transactions to find it and get its "
                     . "short_id, then edit_transaction with only the fields being changed. "
                     . ($isAdmin
                        ? "As admin you can also delete_transaction — always confirm with the user before "
                          . "deleting, since it cannot be undone."
                        : "You cannot delete transactions — that requires an admin; tell the user to ask one if "
                          . "they need something removed.");
        }
    }

    $history = ($fromPhone && $senderName) ? loadRecentProMessages($pdo, $fromPhone) : [];
    $messages = array_merge($history, [['role' => 'user', 'content' => $userText]]);

    $reply = null;
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
            $reply = trim($textOut) ?: null;
            break;
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
            $toolInput = (array)($block['input'] ?? []);
            if ($block['name'] === 'save_contact_report' && $voiceTranscript) $toolInput['_raw_transcript'] = $voiceTranscript;
            $result = runProTool($pdo, $block['name'], $toolInput, (string)$senderRole, $senderId, $senderName);
            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $block['id'],
                'content' => json_encode($result),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }

    if ($reply === null) $reply = "Sorry, that took too long to look up — try asking again, or check the app directly.";

    if ($fromPhone && $senderName) {
        saveProMessage($pdo, $fromPhone, 'user', $userText);
        saveProMessage($pdo, $fromPhone, 'assistant', $reply);
    }

    return $reply;
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_ACCESS_TOKEN,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}
