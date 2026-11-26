<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * lead-meeting-reminders-cron.php — about an hour before a booked meeting, reminds the LEAD (the same way they contacted us) and the assigned
 * account manager. Run every 10 minutes:   *\/10 * * * * php /var/www/socialflow/lead-meeting-reminders-cron.php
 *
 * A meeting is a lead_activities row with type 'meeting' whose followup_date holds "YYYY-MM-DDTHH:MM" (Cairo time), written by the
 * Book meeting shortcut. Only the LATEST meeting of a lead counts (a reschedule replaces the earlier one); each meeting is reminded once
 * (a 'meeting_reminded' activity with the same followup_date is the marker).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lead-send-lib.php';
date_default_timezone_set('Africa/Cairo');
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = new DateTime('now', new DateTimeZone('Africa/Cairo')); $limit = (clone $now)->modify('+75 minutes');
$rows = $pdo->query("SELECT lead_id, followup_date, content FROM lead_activities WHERE type = 'meeting' AND followup_date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$' ORDER BY created_at DESC LIMIT 400")->fetchAll(PDO::FETCH_ASSOC);
$seen = []; $done = [];
foreach ($rows as $r) {
    if (isset($seen[$r['lead_id']])) continue; $seen[$r['lead_id']] = true;   // latest meeting only
    $when = DateTime::createFromFormat('Y-m-d\TH:i', $r['followup_date'], new DateTimeZone('Africa/Cairo'));
    if (!$when || $when < $now || $when > $limit) continue;
    $m = $pdo->prepare("SELECT COUNT(*) FROM lead_activities WHERE lead_id = :l AND type = 'meeting_reminded' AND followup_date = :f"); $m->execute([':l' => $r['lead_id'], ':f' => $r['followup_date']]);
    if ((int)$m->fetchColumn() > 0) continue;
    $q = $pdo->prepare("SELECT id, name, phone, email, client_id, notes, status, assigned_to FROM leads WHERE id = :id"); $q->execute([':id' => $r['lead_id']]); $lead = $q->fetch(PDO::FETCH_ASSOC);
    if (!$lead || in_array($lead['status'], ['closed_won', 'closed_lost', 'dead'], true)) continue;
    $type = (strpos((string)$r['content'], 'phone call') !== false) ? 'phone' : 'office';
    $ctx = lead_context($pdo, $lead);
    $res = lead_send($pdo, $lead, lead_meeting_text($when, $type, $ctx['ar'], $ctx['first'], 'reminder'), $ctx);
    $note = $res['sent'] ? "⏰ Meeting reminder sent to the lead on {$res['channel_label']}." : "⏰ Meeting reminder NOT sent to the lead: " . ($res['send_error'] ?? 'unknown reason');
    $pdo->prepare("INSERT INTO lead_activities (id, lead_id, type, content, followup_date, author_name) VALUES (UUID(), :l, 'meeting_reminded', :c, :f, 'System')")->execute([':l' => $lead['id'], ':c' => $note, ':f' => $r['followup_date']]);
    if (!empty($lead['assigned_to'])) {
        try { $pdo->prepare("INSERT INTO notifications (id, recipient_email, title, message, type, is_read, link_type, link_id) VALUES (UUID(), :e, :t, :m, 'info', 0, 'lead', :l)")
            ->execute([':e' => $lead['assigned_to'], ':t' => 'Meeting soon: ' . $lead['name'], ':m' => 'Meeting at ' . $when->format('H:i') . ' (' . ($type === 'phone' ? 'phone call' : 'office visit') . '). ' . $note, ':l' => $lead['id']]); } catch (Throwable $e) {}
    }
    $done[] = ['lead' => $lead['id'], 'sent' => $res['sent']];
}
echo json_encode(['checked' => count($seen), 'reminded' => $done]) . "\n";
