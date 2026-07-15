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
            'name' => 'find_client',
            'description' => 'Look up existing agency clients by name — use this BEFORE recording a client_payment income transaction, to check whether the client mentioned already exists in the system rather than guessing. Returns close name matches, if any.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Client name or partial name to search for, e.g. "Almousa"'],
                ],
                'required' => ['query'],
            ],
        ],
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
                    'category'    => ['type' => 'string', 'description' => 'For expenses (type=out): salaries, tools, rent (rent & utilities incl. electricity), ads, freelancers, general (general expenses), office_supplies (coffee, water, snacks, pantry items), debt_repayment, partner_mostafa (Mostafa\'s partner withdrawal), partner_radwa (Radwa\'s partner withdrawal), or other. For income (type=in): client_payment, partner_contribution_mostafa (Mostafa injecting cash into the company), partner_contribution_radwa (Radwa injecting cash into the company), or other_income.'],
                    'description' => ['type' => 'string', 'description' => 'Short description, e.g. "April office rent" or "Bank transfer — Acme Co."'],
                    'amount'      => ['type' => 'number'],
                    'currency'    => ['type' => 'string', 'description' => 'Defaults to EGP'],
                    'date'        => ['type' => 'string', 'description' => 'YYYY-MM-DD — defaults to today if omitted'],
                    'method'      => ['type' => 'string', 'enum' => ['Cash', 'Bank transfer', 'Card', 'Other'], 'description' => 'How the money moved, if the user says (e.g. "cash", "bank transfer") — omit if not mentioned.'],
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
                    'method'      => ['type' => 'string', 'enum' => ['Cash', 'Bank transfer', 'Card', 'Other']],
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
    $incomeCats  = ['client_payment', 'partner_contribution_mostafa', 'partner_contribution_radwa', 'other_income'];

    if ($name === 'find_client') {
        $query = trim($input['query'] ?? '');
        if (!$query) return ['error' => 'Missing query.'];
        // Normalized (lowercase, spaces stripped) fuzzy match, e.g. "Almousa" must
        // find "Al Mousa Group" — plain LIKE on the raw name misses that.
        $bind = [':q' => '%' . mb_strtolower($query) . '%'];

        // 1) CRM clients table
        $stmt = $pdo->prepare("SELECT name FROM clients WHERE REPLACE(LOWER(name),' ','') LIKE REPLACE(:q,' ','') ORDER BY name ASC LIMIT 8");
        $stmt->execute($bind);
        $crmMatches = array_map(fn($r) => $r['name'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        // 2) Actual payment-history "clients" — distinct descriptions of client_payment income rows.
        //    This is what the Finance > Clients tab in the app is built from, and is usually the
        //    right place to look since most real clients here aren't in the CRM clients table.
        $stmt = $pdo->prepare("SELECT DISTINCT description AS name, SUM(amount) AS total, COUNT(*) AS payment_count FROM expenses WHERE type='in' AND category='client_payment' AND REPLACE(LOWER(description),' ','') LIKE REPLACE(:q,' ','') GROUP BY description ORDER BY total DESC LIMIT 8");
        $stmt->execute($bind);
        $paymentMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $all = array_values(array_unique(array_merge($crmMatches, array_column($paymentMatches, 'name'))));
        return ['matches' => $all, 'count' => count($all), 'payment_history_details' => $paymentMatches];
    }

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
        $sql = "SELECT id, type, category, description, amount, currency, date, method FROM expenses WHERE 1=1";
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
        foreach (['type', 'category', 'description', 'amount', 'currency', 'date', 'method'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                $sets[] = "$field = :$field";
                $bind[":$field"] = $input[$field];
            }
        }
        if (!$sets) return ['error' => 'No fields to update were provided.'];
        $pdo->prepare("UPDATE expenses SET " . implode(', ', $sets) . " WHERE id = :id")->execute($bind);

        $stmt = $pdo->prepare("SELECT type, category, description, amount, currency, date, method FROM expenses WHERE id = :id");
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

        // Hard guard against the model re-answering an old message and
        // re-calling add_transaction for something it already logged a
        // moment ago — a same-sender/same-amount/same-description row
        // inserted in the last 5 minutes is treated as a repeat, not a new
        // transaction, and rejected outright rather than trusting the
        // prompt instructions alone to prevent it.
        $dupCheck = $pdo->prepare("SELECT ref FROM expenses WHERE type = :type AND description = :desc AND amount = :amt AND created_by = :by AND created_at >= (NOW() - INTERVAL 5 MINUTE) LIMIT 1");
        $dupCheck->execute([':type' => $type, ':desc' => $description, ':amt' => $amount, ':by' => $senderName]);
        $dup = $dupCheck->fetchColumn();
        if ($dup) {
            return ['error' => "This looks like a repeat of a transaction already logged moments ago (ref {$dup}) — did not create a duplicate. If this is genuinely a separate transaction, ask the user to confirm explicitly."];
        }

        $id = generateProUuid();
        $ref = 'TXN-' . strtoupper(substr($id, 0, 8));
        $method = in_array($input['method'] ?? '', ['Cash', 'Bank transfer', 'Card', 'Other'], true) ? $input['method'] : null;
        $ins = $pdo->prepare("INSERT INTO expenses (id, type, category, description, amount, currency, date, created_by, ref, method) VALUES (:id, :type, :cat, :desc, :amt, :cur, :date, :by, :ref, :method)");
        $ins->execute([
            ':id' => $id, ':type' => $type, ':cat' => $category, ':desc' => $description,
            ':amt' => $amount, ':cur' => $currency, ':date' => $date, ':by' => $senderName, ':ref' => $ref, ':method' => $method,
        ]);
        return [
            'ok' => true, 'type' => $type, 'category' => $category, 'description' => $description,
            'amount' => (float)$amount, 'currency' => $currency, 'date' => $date, 'reference' => $ref, 'method' => $method,
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
            'description' => 'Request vacation (paid day off) or WFH (work from home) for yourself. Creates a pending request and immediately notifies your manager over WhatsApp for approval — tell the user you have done this, you cannot approve your own request. ALWAYS ask the user why they need the time off if they have not already said, and pass it as reason — managers need this to decide, and not asking leads to back-and-forth later.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'leave_type'  => ['type' => 'string', 'enum' => ['vacation', 'wfh']],
                    'start_date'  => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'end_date'    => ['type' => 'string', 'description' => 'YYYY-MM-DD — same as start_date for a single day'],
                    'reason'      => ['type' => 'string', 'description' => 'Why they need the time off. Ask for this before calling the tool if the user has not already given one — do not submit without at least trying to get a reason first.'],
                ],
                'required' => ['leave_type', 'start_date', 'reason'],
            ],
        ],
        [
            'name' => 'decide_pending_request',
            'description' => 'Approve or reject a leave/WFH request that is waiting on YOUR approval (see the "Pending leave/WFH requests" list in your context above, if any). Only requests addressed to you as manager can be decided this way. '
                . 'CRITICAL: only call this for a message that is an unambiguous, explicit decision — e.g. "approve it", "approve 9ba2", "reject", "decline", "no". '
                . 'A question ("why?", "why does she need it?"), a request for more info, or anything else that is not a clear yes/no decision is NEVER a call to this tool — just answer the question '
                . 'or use message_team_member to ask the requester, and wait for an explicit decision before acting. Guessing wrong here rejects/approves a real request without the manager meaning to.',
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
            'name' => 'message_team_member',
            'description' => 'Send a short WhatsApp text message to another team member on the sender\'s behalf — e.g. a manager asking a direct report to confirm or fill in missing details on a request. Only works if the sender is that person\'s manager, or the sender is admin. The recipient will see it as a normal message from Pro relaying the sender\'s question.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'member_name' => ['type' => 'string', 'description' => 'Best-effort name match for who to message, e.g. "Monay" for "monaykhalid"'],
                    'message'     => ['type' => 'string', 'description' => 'The message to relay, written as if from the sender (Pro will prefix it saying who it is from)'],
                ],
                'required' => ['member_name', 'message'],
            ],
        ],
        [
            'name' => 'get_my_hr_info',
            'description' => 'Get YOUR OWN salary, vacation/WFH day credits (total/used/remaining), manager, and department. Always scoped to the asker only — never returns a colleague\'s data. When the user asks their own salary (e.g. "how much do I earn", "مرتبي كام"), ALWAYS call this and tell them the number directly — this tool exists specifically for that. Do not refuse, hedge, or redirect them to open the app instead; it is their own data and this is the supported, safe way to give it to them over WhatsApp.',
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

// Mirrors app.jsx's hasPerm(): a role with ANY rows in role_permissions
// uses exactly those (ignores hardcoded defaults entirely); a role with
// no rows at all falls back to the hardcoded DEFAULT_ROLE_PERMISSIONS
// list from app.jsx — currently only 'hr' has hr.manage_recruitment by
// default, extend this array if that ever changes.
function hasRecruitmentPermission(PDO $pdo, string $senderRole) {
    if ($senderRole === 'team:admin') return true;
    $role = str_replace('team:', '', $senderRole);
    $stmt = $pdo->prepare("SELECT permission_key, allowed FROM role_permissions WHERE role = :r");
    $stmt->execute([':r' => $role]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        foreach ($rows as $row) {
            if ($row['permission_key'] === 'hr.manage_recruitment') return (int) $row['allowed'] === 1;
        }
        return false; // has custom rows but recruitment permission isn't among the allowed ones
    }
    return in_array($role, ['hr'], true);
}

function recruitmentTools() {
    return [
        [
            'name' => 'list_job_applications',
            'description' => 'List/search recruitment applications. All filters optional and combinable. Use this to answer questions like "who applied for X", "any new applications", "show shortlisted candidates".',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'status'      => ['type' => 'string', 'enum' => ['new', 'reviewing', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'rejected_after_offer']],
                    'job_title'   => ['type' => 'string', 'description' => 'Partial match on the position applied for'],
                    'name'        => ['type' => 'string', 'description' => 'Partial match on the candidate\'s name'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_job_application',
            'description' => 'Get full details on one specific candidate\'s application — status, AI score/summary, salary expectations, notes, interview/offer info. Use when asked about a specific person by name.',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['name_or_email' => ['type' => 'string', 'description' => 'Candidate name or email — best-effort match']],
                'required' => ['name_or_email'],
            ],
        ],
        [
            'name' => 'update_application_status',
            'description' => 'Move a candidate\'s application to a different pipeline stage — e.g. "shortlist Sarah", "move Ahmed to interview", "reject this one". Does not send any email — it only updates the stage shown in the app.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name_or_email' => ['type' => 'string', 'description' => 'Candidate name or email — best-effort match'],
                    'status'        => ['type' => 'string', 'enum' => ['new', 'reviewing', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'rejected_after_offer']],
                ],
                'required' => ['name_or_email', 'status'],
            ],
        ],
        [
            'name' => 'add_application_note',
            'description' => 'Add an internal note to a candidate\'s application (appended to any existing notes, not shown to the candidate).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name_or_email' => ['type' => 'string', 'description' => 'Candidate name or email — best-effort match'],
                    'note'          => ['type' => 'string'],
                ],
                'required' => ['name_or_email', 'note'],
            ],
        ],
        [
            'name' => 'list_job_openings',
            'description' => 'List job openings (title, department, status, application count). Use for "what positions are open", "how many people applied for X".',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['status' => ['type' => 'string', 'enum' => ['open', 'closed', 'draft']]],
                'required' => [],
            ],
        ],
    ];
}

function findJobApplication(PDO $pdo, string $query) {
    $stmt = $pdo->prepare("SELECT * FROM job_applications WHERE candidate_name LIKE :q OR candidate_email LIKE :q ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':q' => '%' . $query . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function runRecruitmentTool(PDO $pdo, string $name, array $input, string $senderRole, ?string $senderName) {
    if (!hasRecruitmentPermission($pdo, $senderRole)) {
        return ['error' => 'You do not have permission to access recruitment data. Ask an admin or HR to grant you the "Manage Recruitment" permission.'];
    }

    if ($name === 'list_job_applications') {
        $sql = "SELECT candidate_name, candidate_email, job_title, status, ai_score, created_at FROM job_applications WHERE 1=1";
        $params = [];
        if (!empty($input['status']))    { $sql .= " AND status = :s";     $params[':s'] = $input['status']; }
        if (!empty($input['job_title'])) { $sql .= " AND job_title LIKE :j"; $params[':j'] = '%' . $input['job_title'] . '%'; }
        if (!empty($input['name']))      { $sql .= " AND candidate_name LIKE :n"; $params[':n'] = '%' . $input['name'] . '%'; }
        $sql .= " ORDER BY created_at DESC LIMIT 25";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: ['message' => 'No applications match those filters.'];
    }

    if ($name === 'get_job_application') {
        $matches = findJobApplication($pdo, trim($input['name_or_email'] ?? ''));
        if (!$matches) return ['error' => 'No application found matching that name/email.'];
        if (count($matches) > 1) return ['error' => 'Multiple candidates match — be more specific: ' . implode(', ', array_column($matches, 'candidate_name'))];
        $a = $matches[0];
        return [
            'candidate_name' => $a['candidate_name'], 'candidate_email' => $a['candidate_email'], 'candidate_phone' => $a['candidate_phone'],
            'job_title' => $a['job_title'], 'status' => $a['status'], 'ai_score' => $a['ai_score'], 'ai_summary' => $a['ai_summary'],
            'expected_salary' => $a['expected_salary'], 'available_start_date' => $a['available_start_date'],
            'interview_rating' => $a['interview_rating'], 'notes' => $a['notes'],
            'offer_salary' => $a['offer_salary'], 'offer_candidate_response' => $a['offer_candidate_response'],
            'applied_on' => $a['created_at'],
        ];
    }

    if ($name === 'update_application_status') {
        $matches = findJobApplication($pdo, trim($input['name_or_email'] ?? ''));
        if (!$matches) return ['error' => 'No application found matching that name/email.'];
        if (count($matches) > 1) return ['error' => 'Multiple candidates match — be more specific: ' . implode(', ', array_column($matches, 'candidate_name'))];
        $a = $matches[0];
        $status = $input['status'] ?? '';
        $valid = ['new', 'reviewing', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'rejected_after_offer'];
        if (!in_array($status, $valid, true)) return ['error' => 'Invalid status.'];
        $pdo->prepare("UPDATE job_applications SET status = :s WHERE id = :id")->execute([':s' => $status, ':id' => $a['id']]);
        $log = $pdo->prepare("INSERT INTO job_application_activity (id, application_id, action, actor_name) VALUES (UUID(), :aid, :action, :actor)");
        $log->execute([':aid' => $a['id'], ':action' => "Moved to " . ucfirst(str_replace('_', ' ', $status)) . " (via WhatsApp)", ':actor' => $senderName]);
        return ['ok' => true, 'message' => "{$a['candidate_name']}'s application moved to {$status}."];
    }

    if ($name === 'add_application_note') {
        $matches = findJobApplication($pdo, trim($input['name_or_email'] ?? ''));
        if (!$matches) return ['error' => 'No application found matching that name/email.'];
        if (count($matches) > 1) return ['error' => 'Multiple candidates match — be more specific: ' . implode(', ', array_column($matches, 'candidate_name'))];
        $a = $matches[0];
        $note = trim($input['note'] ?? '');
        if (!$note) return ['error' => 'Missing note text.'];
        $combined = trim(($a['notes'] ? $a['notes'] . "\n" : '') . "[{$senderName} via WhatsApp] {$note}");
        $pdo->prepare("UPDATE job_applications SET notes = :n WHERE id = :id")->execute([':n' => $combined, ':id' => $a['id']]);
        return ['ok' => true, 'message' => "Note added to {$a['candidate_name']}'s application."];
    }

    if ($name === 'list_job_openings') {
        $sql = "SELECT o.title, o.department, o.status, (SELECT COUNT(*) FROM job_applications a WHERE a.job_opening_id = o.id) AS application_count FROM job_openings o WHERE 1=1";
        $params = [];
        if (!empty($input['status'])) { $sql .= " AND o.status = :s"; $params[':s'] = $input['status']; }
        $sql .= " ORDER BY o.created_at DESC LIMIT 25";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: ['message' => 'No job openings match those filters.'];
    }

    return ['error' => 'Unknown tool: ' . $name];
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
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return ['error' => 'Could not parse start_date/end_date — use YYYY-MM-DD format.'];
        }
        // A date like "13/6" with no year given is easy to misread into the
        // wrong year. Reject anything before today up front instead of
        // silently forwarding a stale request to the manager for approval.
        $today = strtotime(date('Y-m-d'));
        if ($startTs < $today || $endTs < $today) {
            return ['error' => "That date ({$start}" . ($end !== $start ? " to {$end}" : "") . ") is in the past — today is " . date('Y-m-d') . ". Confirm the correct date (including year) with the user and try again."];
        }
        if ($endTs < $startTs) {
            return ['error' => 'end_date is before start_date — check the dates and try again.'];
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
            $reasonLine = "\nReason: " . (!empty($input['reason']) ? $input['reason'] : '(none given)');
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

    if ($name === 'message_team_member') {
        $query = trim($input['member_name'] ?? '');
        $msg = trim($input['message'] ?? '');
        if (!$query || !$msg) return ['error' => 'Missing member_name/message.'];

        $senderStmt = $pdo->prepare("SELECT role FROM team_members WHERE id = :id");
        $senderStmt->execute([':id' => $senderId]);
        $senderIsAdmin = $senderStmt->fetchColumn() === 'admin';

        $t = $pdo->prepare("SELECT id, name, whatsapp_number, manager_id FROM team_members WHERE name LIKE :n LIMIT 5");
        $t->execute([':n' => '%' . $query . '%']);
        $matches = $t->fetchAll(PDO::FETCH_ASSOC);
        if (!$matches) return ['error' => "No team member found matching \"{$query}\"."];
        if (count($matches) > 1) {
            return ['error' => 'Multiple team members match "' . $query . '": ' . implode(', ', array_column($matches, 'name')) . '. Ask which one they mean.'];
        }
        $target = $matches[0];

        if (!$senderIsAdmin && $target['manager_id'] !== $senderId) {
            return ['error' => 'You can only message your own direct reports this way (or be admin).'];
        }
        if (empty($target['whatsapp_number'])) {
            return ['error' => "{$target['name']} doesn't have a WhatsApp number on file — can't relay a message to them."];
        }

        sendWhatsAppReply($target['whatsapp_number'], "💬 Message from {$senderName}:\n\n{$msg}");
        return ['ok' => true, 'message' => "Sent to {$target['name']} over WhatsApp."];
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
    if (in_array($name, ['find_client', 'get_finance_summary', 'search_transactions', 'add_transaction', 'edit_transaction', 'delete_transaction'], true)) {
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

        // DESC, not ASC — with only 15 rows returned, ascending order meant
        // a client with more than 15 posts could have its true most recent
        // one truncated off entirely, so "last published post" questions
        // were answered from stale/old data instead of the real latest one.
        $sql .= " ORDER BY scheduled_date DESC LIMIT 15";
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
        $system = "You are Pro, the AI assistant built into SocialFlow. Today's date is " . date('Y-m-d') . " ("
                . date('l') . "). When the user gives you a date without a year (e.g. \"13/6\" or \"next Tuesday\"), "
                . "resolve it relative to today and assume the nearest occurrence on or after today — never assume "
                . "a past year. If a date is genuinely ambiguous, confirm it with the user before acting on it, "
                . "especially before submitting anything (like a time-off request) that another person will "
                . "review. You are replying over WhatsApp to "
                . "{$senderName} (role: {$senderRole}). Keep replies short — a few sentences max, WhatsApp-style, "
                . "no markdown headers. ALWAYS reply in the same language the user just wrote in — if their message "
                . "is in English, reply in English; if Arabic, reply in Arabic; if mixed/Arabizi, match their style. "
                . "This applies to the ENTIRE message, every single word — including sign-offs, closing questions "
                . "like \"anything else?\", and small talk. Do not let a closing phrase slip into a different "
                . "language just because you (or the user) used it in an earlier turn — check the language of "
                . "EVERY sentence you write against the user's current message before sending, not just the main "
                . "content. Never switch languages on them mid-conversation just because an earlier message in the "
                . "thread was in a different language — always mirror the CURRENT message, fully. Only answer their "
                . "CURRENT message — the conversation history is for your context (pronouns, follow-ups, \"the same "
                . "one\", etc.), never something to recap, repeat, or continue, and NEVER something to act on again. "
                . "Do not paste, restate, or append an earlier answer (a table, a list, a report) into a new reply "
                . "unless they explicitly ask for it again — a totally unrelated new request (e.g. logging a "
                . "payment) gets ONLY its own short answer, nothing carried over from a previous topic. This is "
                . "critical for any tool that changes data (add_transaction, edit_transaction, delete_transaction, "
                . "request_time_off, decide_pending_request, etc.): only call a data-changing tool for something "
                . "the user's CURRENT message is actually asking for right now — never re-call one for something "
                . "that only appears in earlier history (an old confirmed amount, name, or action), even if it's "
                . "still visible in the conversation above. If their current message asks for ONE thing, take "
                . "ONE action, not one plus a repeat of something already done earlier. You can see this context about "
                . "them:\n\n{$contextBlock}\n\n"
                . "Only answer questions related to their SocialFlow work (clients, tasks, the agency). Politely "
                . "decline anything unrelated (general trivia, unrelated requests) and steer back to SocialFlow. "
                . "If asked to take an action you can't perform over WhatsApp (e.g. editing a post), tell them "
                . "to open the SocialFlow app to do it, but still answer their question as best you can here.\n\n"
                . "You can also handle HR requests: asking about their own salary/vacation/WFH credits "
                . "(get_my_hr_info — including their exact salary number when asked, e.g. \"how much do I earn\"/"
                . "\"مرتبي كام\"; always answer directly with the figure, never refuse or redirect them to the app "
                . "for their own data), requesting vacation or work-from-home (request_time_off), and — if they "
                . "manage other people — approving or rejecting requests waiting on them (decide_pending_request, "
                . "see their pending approvals list above if any). These tools only ever touch the asker's own "
                . "record or requests explicitly addressed to them. NEVER call decide_pending_request unless the "
                . "message is an explicit, unambiguous approve/reject decision — a question like \"why?\" is NOT a "
                . "decision, just answer it. If a manager wants to check something with the requester first (e.g. "
                . "\"ask her why\"), use message_team_member to actually relay that over WhatsApp instead of saying "
                . "you can't — you can, for their own direct reports (or any team member, if the asker is admin)."
                . ($voiceTranscript
                    ? "\n\nThis message is a transcript of a VOICE NOTE they just sent you — it may contain "
                      . "transcription errors, use your judgement. If it sounds like a debrief of a client call/"
                      . "meeting (mentions a client and what was discussed), extract and save it with "
                      . "save_contact_report, then confirm briefly what you saved. If it's just a normal question "
                      . "instead, answer it normally like any other message."
                    : '');

        // Recruitment tools follow the app's own Roles & Permissions
        // (hr.manage_recruitment) rather than a hardcoded role list — same
        // check the app UI itself uses to show/hide the Recruitment page.
        $canRecruit = $isTeam && hasRecruitmentPermission($pdo, $senderRole);

        if ($isAdmin) {
            $tools = array_merge(proTools(), hrTools(), financeTools(), recruitmentTools());
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
        if ($canRecruit && !$isAdmin) {
            $tools = array_merge($tools, recruitmentTools());
        }
        if ($canRecruit) {
            $system .= "\n\nRecruitment: you can list/search job applications and openings, look up a specific "
                     . "candidate, move an application to a new pipeline stage (list_job_applications, "
                     . "get_job_application, update_application_status), and add an internal note "
                     . "(add_application_note). These never send any email to the candidate — they only update "
                     . "what's shown in the app's Recruitment page.";
        }
        if ($isAdmin || $isAccountant) {
            $system .= "\n\nFinancial data: you can answer any question about money in, money out, balance, or "
                     . "spending by category using get_finance_summary and search_transactions — always use "
                     . "these tools rather than guessing numbers. To record a new income or expense with "
                     . "add_transaction, you need: whether it's money IN or OUT, an amount, a short description, "
                     . "and (ideally) a category. If the user's message is missing any of these or is ambiguous "
                     . "(e.g. they just say \"add 500 for coffee\" without saying in/out — assume OUT for an "
                     . "expense-sounding request, but ask if genuinely unclear), ask a short follow-up question "
                     . "instead of guessing. If they mention how the money moved (cash, bank transfer, card), pass "
                     . "it as method — don't ask for it separately, just capture it when given. After successfully "
                     . "saving, always confirm back to the user exactly what was recorded (amount, type, category, "
                     . "description, date, method if given) in one short line.\n\n"
                     . "Whenever the category is client_payment (money IN from a client), ALWAYS call find_client "
                     . "with the client name mentioned BEFORE saving — do not skip this, and use it any time a "
                     . "client name comes up, not just when adding a transaction (e.g. \"how much did X pay\" — "
                     . "look X up first, don't assume you know the exact name they use in the system). find_client "
                     . "matches loosely (case/spacing insensitive) and searches actual payment history, not just "
                     . "the CRM client list, so \"Almousa\" WILL find \"Al Mousa Group\" — trust its matches, don't "
                     . "second-guess a close match as \"not found\". If it returns a close match, always use that "
                     . "exact name from the system (not the user's spelling) in the description or when calling "
                     . "search_transactions/get_finance_summary. If it returns genuinely no match at all, stop and "
                     . "ask the user to confirm before saving — e.g. \"I don't see a client called '{name}' in the "
                     . "system — should I record this payment anyway?\" — and only call add_transaction after they "
                     . "confirm. Never silently record a payment for an unknown client without asking first.\n\n"
                     . "For questions about a client's payments: call find_client first to resolve the exact name. "
                     . "Its result includes an ALL-TIME total and payment_count per matching payer, which is enough "
                     . "if the question has no time period (e.g. \"how much has X paid overall\"). If the question "
                     . "is scoped to a period (e.g. \"this year\", \"last month\", \"in 2026\"), do NOT use those "
                     . "all-time numbers — instead call search_transactions with the exact name and the matching "
                     . "start_date/end_date, then sum the amounts yourself from the results.\n\n"
                     . "Descriptions and any free text you save (transaction descriptions, comments, etc.) must "
                     . "always be in English — if the user writes in Arabic or any other language, translate it "
                     . "to English yourself before saving, even though you understood their message fine as-is.\n\n"
                     . "To change something already recorded, use search_transactions to find it and get its "
                     . "short_id, then edit_transaction with only the fields being changed. "
                     . ($isAdmin
                        ? "As admin you can also delete_transaction — always confirm with the user before "
                          . "deleting, since it cannot be undone. Once you've proposed specific short_ids to delete "
                          . "and the user confirms (\"yes\", \"remove them\", \"go ahead\", etc.), you already have "
                          . "everything you need — call delete_transaction for EACH of those exact short_ids "
                          . "immediately in that same reply. Do NOT re-run search_transactions or re-list the "
                          . "transactions again first — that just repeats the same question forever without ever "
                          . "deleting anything. Only re-search if the user's confirmation is ambiguous about which "
                          . "specific ones they mean."
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
            // A failed API call used to return null here directly, which
            // skipped the friendly fallback message below (only reached via
            // the loop ending naturally) — the caller's `if ($reply)` check
            // then silently dropped the whole reply, with no message sent
            // to the user and no trace of why beyond "askPro returned NULL".
            error_log('[askPro] Claude API call failed: HTTP ' . $status . ' ' . substr((string)$raw, 0, 500));
            break;
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
    if (!defined('WA_PHONE_ID') || !defined('WA_ACCESS_TOKEN') || !WA_PHONE_ID || !WA_ACCESS_TOKEN) {
        error_log('[wa-webhook] sendWhatsAppReply: WA_PHONE_ID/WA_ACCESS_TOKEN not configured');
        return;
    }
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
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        error_log('[wa-webhook] sendWhatsAppReply failed: HTTP ' . $status . ' ' . ($err ?: $res));
    }
}
