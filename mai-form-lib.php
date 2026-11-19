<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
// ================================================================
// Mai's reports as a LINK (no login): Mai sends a WhatsApp message with a private link; the link opens a step-by-step
// form (mai-report.php). When the last step is submitted Mai scores it, applies what was reported (lead notes, client
// memory, admin notes) and sends a WhatsApp confirmation that repeats what was filled in.
//
// A session built here has mode='form'. Its steps and answers live in mai_report_sessions.answers as
//   {"steps":[...], "a":{ "<checklist key>": {choice, items:{id:value}, notes:{id:text}, note, text} }}
// Needs vps-migration/migration-mai-form.sql (token, answers, mode columns). Without it everything falls back to the old chat.
// ================================================================
require_once __DIR__ . '/mai-report-lib.php';

/** True once the migration has been run (cached). */
function maiFormReady(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT token, answers, mode FROM mai_report_sessions LIMIT 0"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

function maiFormBaseUrl(): string {
    return rtrim(defined('MAI_FORM_BASE_URL') && MAI_FORM_BASE_URL ? MAI_FORM_BASE_URL : 'https://socialflow.admepro.com', '/');
}
function maiFormLink(string $token): string { return maiFormBaseUrl() . '/mai-report.php?t=' . $token; }

const MAI_FORM_YESNO = [['yes', 'Yes', true], ['no', 'No', false]];

/** Does a step have an answer for every question on it? (mirrored in the page's JavaScript) */
function maiFormStepDone(array $step, $ans): bool {
    $ans = is_array($ans) ? $ans : [];
    if (($step['type'] ?? '') === 'text') return trim((string)($ans['text'] ?? '')) !== '';
    if (($step['type'] ?? '') === 'list') {
        foreach ($step['items'] as $it) if (empty($ans['items'][$it['id']])) return false;
        return true;
    }
    return !empty($ans['choice']);
}
/** Counts as "done" for the score: every answer is a good one (text: something was written). */
function maiFormStepOk(array $step, $ans): bool {
    if (!maiFormStepDone($step, $ans)) return false;
    $ans = is_array($ans) ? $ans : [];
    if (($step['type'] ?? '') === 'text') return true;
    $okVals = array_column(array_filter($step['choices'], fn($c) => !empty($c[2])), 0);
    if (($step['type'] ?? '') === 'list') {
        foreach ($step['items'] as $it) if (!in_array($ans['items'][$it['id']], $okVals, true)) return false;
        return true;
    }
    return in_array($ans['choice'], $okVals, true);
}

function maiFormTodayClients(PDO $pdo, $amId): array {
    $s = $pdo->prepare("SELECT id, name FROM clients WHERE account_manager_id = :id AND status = 'active' ORDER BY name");
    $s->execute([':id' => $amId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** One step per checklist item, filled with the person's real clients / posts / leads / routine tasks. */
function maiFormBuildSteps(PDO $pdo, array $am, string $reportType, array $checklist): array {
    $steps = [];
    $clients = null; $posts = null; $leads = null;
    $clientItems = function () use ($pdo, $am, &$clients) {
        if ($clients === null) $clients = array_map(fn($c) => ['id' => $c['id'], 'label' => $c['name'], 'name' => $c['name']], maiFormTodayClients($pdo, $am['id']));
        return $clients;
    };
    foreach ($checklist as $key => $label) {
        $label = (string)$label; $s = ['key' => $key, 'label' => $label, 'type' => 'yesno', 'choices' => MAI_FORM_YESNO, 'items' => [], 'title' => $label, 'prompt' => $label . '?'];
        switch ($key) {
            case 'checked_platforms':
                $s['title'] = 'Social platforms'; $s['prompt'] = 'Did you check the social platforms for each client today?';
                if ($items = $clientItems()) { $s['type'] = 'list'; $s['items'] = $items; }
                break;
            case 'checked_ad_accounts':
                $s['title'] = 'Ad accounts'; $s['prompt'] = 'Did you check the ad accounts for each client today?';
                if ($items = $clientItems()) { $s['type'] = 'list'; $s['items'] = $items; }
                break;
            case 'answered_comms':
                $s['title'] = 'Client messages & calls'; $s['prompt'] = 'Have you answered all client calls and messages?';
                break;
            case 'confirmed_posting':
                $s['title'] = "Today's posts"; $s['prompt'] = 'Is each of today\'s posts published or scheduled?';
                if ($posts === null) {
                    $posts = [];
                    foreach ($clientItems() as $c) {
                        $q = $pdo->prepare("SELECT id, title, stage FROM posts WHERE client_id = :cid AND scheduled_date = CURDATE() ORDER BY title");
                        $q->execute([':cid' => $c['id']]);
                        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
                            $live = in_array($p['stage'], ['published', 'scheduled'], true);
                            $posts[] = ['id' => $p['id'], 'label' => $c['name'] . ' — ' . $p['title'], 'name' => $c['name'], 'sub' => $live ? 'Already ' . $p['stage'] : 'Still in: ' . str_replace('_', ' ', $p['stage'])];
                        }
                    }
                }
                if ($posts) { $s['type'] = 'list'; $s['items'] = $posts; $s['choices'] = [['yes', 'Going out', true], ['no', 'Not going out', false]]; }
                else { $s['prompt'] = 'No posts are scheduled for today on file. Is everything that should go out today scheduled?'; }
                break;
            case 'confirmed_meetings':
                $s['title'] = 'Meetings'; $s['prompt'] = "Are today's meetings confirmed?"; break;
            case 'confirmed_submissions':
                $s['title'] = 'Submissions'; $s['prompt'] = "Are today's submissions / deliverables on track?"; break;
            case 'confirmed_lead_followups':
                $s['title'] = 'Lead follow-ups'; $s['prompt'] = 'Did you follow up with each of your open leads?';
                if ($leads === null) {
                    $q = $pdo->prepare("SELECT id, name, company, status FROM leads WHERE assigned_to = :e AND status NOT IN ('closed_won','closed_lost','dead') AND (client_name IS NULL OR LOWER(client_name) = 'admepro') ORDER BY assigned_at DESC LIMIT 15");
                    $q->execute([':e' => $am['email']]);
                    $leads = array_map(fn($l) => ['id' => $l['id'], 'label' => $l['name'], 'sub' => trim(($l['company'] ? $l['company'] . ' · ' : '') . str_replace('_', ' ', (string)$l['status']))], $q->fetchAll(PDO::FETCH_ASSOC));
                }
                if ($leads) { $s['type'] = 'list'; $s['items'] = $leads; $s['choices'] = [['called', 'Called / messaged', true], ['tried', 'No answer', true], ['later', 'Not yet', false]]; }
                else { $s['prompt'] = 'You have no open leads right now. Any lead follow-ups planned for today?'; }
                break;
            case 'routine_tasks':
                $s['title'] = 'Routine tasks';
                $q = $pdo->prepare("SELECT id, title FROM posts WHERE is_routine = 1 AND assigned_to = :e AND LEFT(due_date,10) = CURDATE() ORDER BY due_time");
                $q->execute([':e' => $am['email']]);
                $r = array_map(fn($p) => ['id' => $p['id'], 'label' => $p['title']], $q->fetchAll(PDO::FETCH_ASSOC));
                if (!$r) continue 2;   // nothing to ask about today
                $s['type'] = 'list'; $s['items'] = $r; $s['prompt'] = 'Which of today\'s routine tasks are done?'; $s['choices'] = [['done', 'Done', true], ['not', 'Not done', false]];
                break;
            case 'meetings_logged':
                $s['title'] = 'Contact reports'; $s['prompt'] = "Does each of today's meetings have a contact report logged?"; break;
            case 'submissions_done':
                $s['title'] = 'Deliveries'; $s['prompt'] = "Were today's submissions / deliverables actually delivered?"; break;
            case 'morning_items_done':
                $s['title'] = 'Morning items'; $s['prompt'] = 'Are the items left open this morning now resolved or explained?'; break;
            case 'key_updates':    $s['type'] = 'text'; $s['title'] = 'Main updates';        $s['prompt'] = 'What are the main things to note in the system from today? (client changes, decisions, deadlines)'; break;
            case 'client_risks':   $s['type'] = 'text'; $s['title'] = 'Client risks';        $s['prompt'] = 'Any client issues, risks or escalations? Write "none" if there are none.'; break;
            case 'opportunities':  $s['type'] = 'text'; $s['title'] = 'Opportunities';       $s['prompt'] = 'Any new opportunities, leads or upsells? Write "none" if there are none.'; break;
            case 'team_blockers':  $s['type'] = 'text'; $s['title'] = 'Team blockers';       $s['prompt'] = 'Any team issues or blockers that need action? Write "none" if there are none.'; break;
            case 'admin_attention':$s['type'] = 'text'; $s['title'] = 'Needs the admin';     $s['prompt'] = 'Anything that needs the admin\'s attention or a decision? Write "none" if there is nothing.'; break;
        }
        if ($s['type'] === 'text') { $s['choices'] = []; }
        $steps[] = $s;
    }
    return $steps;
}

/** Starts today's report as a link. Returns the session id, or null (already started / opted out / not set up). */
function maiStartFormSession(PDO $pdo, array $am, string $reportType) {
    if (!maiFormReady($pdo)) return false;   // false = migration missing: caller falls back to the chat flow
    $today = date('Y-m-d');
    $exists = $pdo->prepare("SELECT id FROM mai_report_sessions WHERE account_manager_id = :id AND report_type = :t AND report_date = :d");
    $exists->execute([':id' => $am['id'], ':t' => $reportType, ':d' => $today]);
    if ($exists->fetchColumn()) return null;
    if (!waPrefAllows($pdo, $am['email'], 'wa_am_reports')) return null;

    $checklist = maiChecklistForAm($am, $reportType);
    $steps = maiFormBuildSteps($pdo, $am, $reportType, $checklist);
    if (!$steps) return null;
    $state = [];
    foreach ($steps as $st) $state[$st['key']] = ['label' => $st['label'], 'done' => false, 'note' => null];

    $token = bin2hex(random_bytes(16));
    $sessionId = generateProUuid();
    $first = explode(' ', trim($am['name']))[0];
    $when = $reportType === 'director' ? 'end-of-day' : ($reportType === 'morning' ? 'morning' : 'end-of-day');
    $link = maiFormLink($token);
    $msg = ($reportType === 'morning' ? "Good day {$first}! ☀️" : "Hi {$first}! 🌙") . " Your {$when} report is ready. It takes about 2 minutes, one step at a time, and you don't need to log in. Open it here: {$link}";

    $pdo->prepare("INSERT INTO mai_report_sessions (id, account_manager_id, account_manager_name, account_manager_email, report_type, report_date, status, checklist, transcript, token, answers, mode)
                   VALUES (:id, :amid, :n, :e, :t, :d, 'in_progress', :cl, :tr, :tok, :ans, 'form')")
        ->execute([':id' => $sessionId, ':amid' => $am['id'], ':n' => $am['name'], ':e' => $am['email'], ':t' => $reportType, ':d' => $today,
            ':cl' => json_encode($state), ':tr' => json_encode([['role' => 'assistant', 'content' => $msg, 'at' => date('c')]]),
            ':tok' => $token, ':ans' => json_encode(['steps' => $steps, 'a' => new stdClass()], JSON_UNESCAPED_UNICODE)]);
    if (!empty($am['whatsapp_number'])) sendWhatsAppReply($am['whatsapp_number'], $msg);
    return $sessionId;
}

/** One line per step for the WhatsApp confirmation, the admin digest and the in-app transcript. */
function maiFormSummaryLines(array $steps, array $answers): array {
    $lines = [];
    foreach ($steps as $s) {
        $a = $answers[$s['key']] ?? [];
        $t = $s['title'];
        if ($s['type'] === 'text') { $lines[] = "{$t}: " . trim(preg_replace('/\s+/', ' ', (string)($a['text'] ?? '—'))); continue; }
        if ($s['type'] === 'list') {
            $okVals = array_column(array_filter($s['choices'], fn($c) => !empty($c[2])), 0);
            $bad = [];
            foreach ($s['items'] as $it) {
                $v = $a['items'][$it['id']] ?? '';
                if (!in_array($v, $okVals, true)) {
                    $lab = ''; foreach ($s['choices'] as $c) if ($c[0] === $v) $lab = $c[1];
                    $n = trim((string)($a['notes'][$it['id']] ?? ''));
                    $bad[] = $it['label'] . ($lab ? " ({$lab})" : '') . ($n !== '' ? ": {$n}" : '');
                }
            }
            $lines[] = $bad ? "✗ {$t}: " . implode('; ', $bad) : "✓ {$t}: all " . count($s['items']);
            continue;
        }
        $ok = ($a['choice'] ?? '') === 'yes'; $n = trim((string)($a['note'] ?? ''));
        $lines[] = ($ok ? '✓ ' : '✗ ') . $t . ($ok ? '' : ($n !== '' ? ": {$n}" : ': no'));
    }
    return $lines;
}

/** Scores the finished form, applies what was reported and closes the session. Returns [score, max, confirmationText]. */
function maiFormFinalize(PDO $pdo, array $session): array {
    $data = json_decode((string)($session['answers'] ?? ''), true) ?: [];
    $steps = $data['steps'] ?? []; $answers = $data['a'] ?? [];
    $checklist = json_decode((string)($session['checklist'] ?? ''), true) ?: [];
    $email = $session['account_manager_email'];
    $isDir = $session['report_type'] === 'director';
    $score = 0; $summary = ['notes' => [], 'facts' => 0, 'leads' => 0];
    $admins = $isDir ? $pdo->query("SELECT email FROM team_members WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) : [];
    $leadFind = $pdo->prepare("SELECT id, status FROM leads WHERE id = :id AND assigned_to = :e LIMIT 1");

    foreach ($steps as $s) {
        $k = $s['key']; $a = $answers[$k] ?? [];
        $ok = maiFormStepOk($s, $a);
        if (isset($checklist[$k])) { $checklist[$k]['done'] = $ok; $checklist[$k]['note'] = $s['type'] === 'text' ? trim((string)($a['text'] ?? '')) : (trim((string)($a['note'] ?? '')) ?: null); }
        if ($ok) $score += 10;

        if ($s['type'] === 'list') {
            foreach ($s['items'] as $it) {
                $v = $a['items'][$it['id']] ?? ''; $note = trim((string)($a['notes'][$it['id']] ?? ''));
                if ($k === 'confirmed_lead_followups' && in_array($v, ['called', 'tried'], true)) {
                    $leadFind->execute([':id' => $it['id'], ':e' => $email]); $l = $leadFind->fetch(PDO::FETCH_ASSOC);
                    if ($l) {
                        $text = ($v === 'called' ? 'Followed up today (AM report)' : 'Tried to reach them today, no answer (AM report)') . ($note !== '' ? ": {$note}" : '');
                        $pdo->prepare("INSERT INTO lead_activities (id, lead_id, content, author_name, type) VALUES (UUID(), :lid, :c, 'Mai (AM report form)', 'note')")->execute([':lid' => $l['id'], ':c' => $text]);
                        if ($l['status'] === 'new' && $v === 'called') $pdo->prepare("UPDATE leads SET status = 'contacted' WHERE id = :id")->execute([':id' => $l['id']]);
                        $summary['leads']++;
                    }
                } elseif (in_array($k, ['checked_platforms', 'checked_ad_accounts', 'confirmed_posting', 'morning_items_done'], true) && $note !== '' && !empty($it['name'])) {
                    $c = $pdo->prepare("SELECT id FROM clients WHERE name = :n LIMIT 1"); $c->execute([':n' => $it['name']]); $cid = $c->fetchColumn();
                    if ($cid) { $pdo->prepare("INSERT INTO client_memory (id, client_id, client_name, `key`, value, type) VALUES (:id, :cid, :cn, :k, :v, 'am_daily_report')")
                        ->execute([':id' => generateProUuid(), ':cid' => $cid, ':cn' => $it['name'], ':k' => 'am_report_' . $k, ':v' => $note]); $summary['facts']++; }
                }
            }
        } elseif ($s['type'] === 'text' && $isDir) {
            $txt = trim((string)($a['text'] ?? ''));
            if ($txt !== '' && !preg_match('/^(none|no|nothing|مفيش|لا)\.?$/iu', $txt)) {
                $prio = in_array($k, ['client_risks', 'admin_attention'], true) ? 'high' : 'normal';
                $summary['notes'][] = ['title' => $s['title'], 'detail' => $txt, 'priority' => $prio];
                foreach ($admins as $ae) {
                    try { $pdo->prepare("INSERT INTO notifications (id, recipient_email, title, message, type, is_read) VALUES (UUID(), :e, :t, :m, :ty, 0)")
                        ->execute([':e' => $ae, ':t' => 'Account Director note: ' . $s['title'], ':m' => $txt, ':ty' => $prio === 'high' ? 'warning' : 'info']); } catch (Throwable $e) {}
                }
            }
        }
    }
    $max = count($steps) * 10;
    $lines = maiFormSummaryLines($steps, $answers);
    $first = explode(' ', trim((string)$session['account_manager_name']))[0];
    $label = $session['report_type'] === 'morning' ? 'morning' : 'end-of-day';
    $body = "✅ {$first}, your {$label} report is done: {$score}/{$max}.\nWhat you reported:\n" . implode("\n", array_map(fn($l) => '• ' . $l, $lines)) . "\nThank you! 🙌 Mai";
    if (mb_strlen($body) > 900) $body = mb_substr($body, 0, 890) . '…';

    $transcript = json_decode((string)($session['transcript'] ?? ''), true) ?: [];
    $transcript[] = ['role' => 'user', 'content' => implode(' | ', $lines), 'at' => date('c')];
    $transcript[] = ['role' => 'assistant', 'content' => $body, 'at' => date('c')];
    $pdo->prepare("UPDATE mai_report_sessions SET status = 'completed', checklist = :cl, transcript = :tr, score = :s, max_score = :m, completed_at = NOW() WHERE id = :id")
        ->execute([':cl' => json_encode($checklist), ':tr' => json_encode($transcript), ':s' => $score, ':m' => $max, ':id' => $session['id']]);
    if ($isDir) { try { $pdo->prepare("UPDATE mai_report_sessions SET summary = :s WHERE id = :id")->execute([':s' => json_encode($summary, JSON_UNESCAPED_UNICODE), ':id' => $session['id']]); } catch (Throwable $e) {} }

    $phone = null;
    if (!empty($session['account_manager_id'])) { $p = $pdo->prepare("SELECT whatsapp_number FROM team_members WHERE id = :id"); $p->execute([':id' => $session['account_manager_id']]); $phone = $p->fetchColumn(); }
    if ($phone) sendWhatsAppReply($phone, $body);
    return [$score, $max, $body, $lines];
}
