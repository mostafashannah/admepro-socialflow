<?php
/**
 * lead-rotation-sweep-cron.php — reassigns admepro's own leads (Leads &
 * CRM page) that have sat unactioned past the configured timeout.
 *
 * "Took action" = the lead's status moved off 'new', OR a lead_activities
 * note was logged after it was assigned. Normally reassigns to the next
 * account manager in rotation order (skipping anyone who's themselves
 * currently sitting on an overdue lead, so a slow responder doesn't keep
 * accumulating new ones). If the CURRENT assignee has missed
 * missed_threshold-or-more leads in the trailing 30 days, though, this
 * one instead goes to whichever active account manager has the best
 * closed-won rate — chronic missers get bumped out of their normal turn
 * in favor of whoever's actually converting.
 *
 * Settings: app_settings.lead_rotation_settings (Leads & CRM → gear icon).
 * Run every 30-60 min:
 *   0,30 * * * * /usr/bin/php /var/www/socialflow/lead-rotation-sweep-cron.php >> /var/www/socialflow/lead-rotation-cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php'; // sendWhatsAppReply(), waPrefAllows()

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$settingsRow = $pdo->query("SELECT id, lead_rotation_settings FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cfg = $settingsRow ? (json_decode($settingsRow['lead_rotation_settings'] ?? '{}', true) ?: []) : [];
if (empty($cfg['enabled']) || empty($cfg['rotation_order'])) { echo json_encode(['skipped' => 'rotation disabled or not configured']); exit; }

$timeoutHours = max(1, (int)($cfg['timeout_hours'] ?? 24));
$missedThreshold = max(1, (int)($cfg['missed_threshold'] ?? 3));
$order = array_values($cfg['rotation_order']);
$n = count($order);

// Overdue = still 'new' (no status change = no action), assigned more than
// timeout_hours ago, not already flagged, and admepro's own pipeline only
// (client_name null/admepro — matches the same filter the Leads page uses).
$overdueStmt = $pdo->prepare(
    "SELECT * FROM leads WHERE status = 'new' AND assigned_to IS NOT NULL AND assigned_at IS NOT NULL
     AND assigned_at <= (NOW() - INTERVAL {$timeoutHours} HOUR) AND rotation_missed = 0
     AND (client_name IS NULL OR LOWER(client_name) = 'admepro')"
);
$overdueStmt->execute();
$overdueLeads = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);

// A lead with logged follow-up activity after assignment isn't actually
// missed — activity happened, just no status change yet.
$activityStmt = $pdo->prepare("SELECT 1 FROM lead_activities WHERE lead_id = :lid AND created_at > :since LIMIT 1");

$amRow = $pdo->prepare("SELECT id, name, email FROM team_members WHERE id = :id AND status = 'active' LIMIT 1");
$missedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = :email AND rotation_missed = 1 AND created_at >= (NOW() - INTERVAL 30 DAY)");
$closeRateStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'closed_won') AS won FROM leads WHERE assigned_to = :email");

$reassigned = 0;
foreach ($overdueLeads as $lead) {
    $activityStmt->execute([':lid' => $lead['id'], ':since' => $lead['assigned_at']]);
    if ($activityStmt->fetchColumn()) continue; // real activity happened — not actually missed

    // Mark the miss on the current assignment before figuring out who's next.
    $pdo->prepare("UPDATE leads SET rotation_missed = 1 WHERE id = :id")->execute([':id' => $lead['id']]);

    $currentEmail = $lead['assigned_to'];
    $missedCountStmt->execute([':email' => $currentEmail]);
    $isChronicMisser = (int)$missedCountStmt->fetchColumn() >= $missedThreshold;

    $nextAM = null;
    if ($isChronicMisser) {
        // Best closed-won rate among active AMs in rotation, excluding the
        // chronic misser — needs at least 1 lead on record to be ranked
        // (an AM with zero leads has no real signal either way).
        $best = null; $bestRate = -1;
        foreach ($order as $id) {
            $amRow->execute([':id' => $id]);
            $am = $amRow->fetch(PDO::FETCH_ASSOC);
            if (!$am || !$am['email'] || $am['email'] === $currentEmail) continue;
            $closeRateStmt->execute([':email' => $am['email']]);
            $stats = $closeRateStmt->fetch(PDO::FETCH_ASSOC);
            $total = (int)($stats['total'] ?? 0);
            if ($total < 1) continue;
            $rate = ((int)($stats['won'] ?? 0)) / $total;
            if ($rate > $bestRate) { $bestRate = $rate; $best = $am; }
        }
        $nextAM = $best;
    }
    if (!$nextAM) {
        // Normal case: next-in-rotation, skipping anyone else already
        // sitting on an overdue lead of their own.
        $currentIdx = array_search($lead['assigned_to'], array_map(function ($id) use ($amRow) {
            $amRow->execute([':id' => $id]);
            $r = $amRow->fetch(PDO::FETCH_ASSOC);
            return $r['email'] ?? null;
        }, $order));
        $start = $currentIdx === false ? 0 : $currentIdx + 1;
        for ($i = 0; $i < $n; $i++) {
            $idx = ($start + $i) % $n;
            $amRow->execute([':id' => $order[$idx]]);
            $am = $amRow->fetch(PDO::FETCH_ASSOC);
            if (!$am || !$am['email'] || $am['email'] === $currentEmail) continue;
            $overdueCheck = $pdo->prepare("SELECT 1 FROM leads WHERE assigned_to = :email AND rotation_missed = 1 AND status NOT IN ('closed_won','closed_lost') LIMIT 1");
            $overdueCheck->execute([':email' => $am['email']]);
            if (!$overdueCheck->fetchColumn()) { $nextAM = $am; break; }
            if (!$nextAM) $nextAM = $am; // fallback if literally everyone's behind
        }
    }
    if (!$nextAM || $nextAM['email'] === $currentEmail) continue; // nowhere better to send it

    $pdo->prepare("UPDATE leads SET assigned_to = :email, assigned_at = NOW(), rotation_missed = 0 WHERE id = :id")
        ->execute([':email' => $nextAM['email'], ':id' => $lead['id']]);

    $reasonNote = $isChronicMisser
        ? "Reassigned from " . ($currentEmail ?: 'unassigned') . " to {$nextAM['name']} — repeated missed leads, moved to best-closing account manager instead of next-in-line."
        : "Reassigned from " . ($currentEmail ?: 'unassigned') . " to {$nextAM['name']} — no action taken within {$timeoutHours}h (round-robin).";
    $pdo->prepare("INSERT INTO lead_activities (id, lead_id, content, author_name, type) VALUES (UUID(), :lid, :content, 'Lead Rotation', 'note')")
        ->execute([':lid' => $lead['id'], ':content' => $reasonNote]);

    // Notify both sides over WhatsApp, respecting each person's own opt-out.
    $oldAmStmt = $pdo->prepare("SELECT name, whatsapp_number FROM team_members WHERE email = :email LIMIT 1");
    $oldAmStmt->execute([':email' => $currentEmail]);
    $oldAm = $oldAmStmt->fetch(PDO::FETCH_ASSOC);
    if ($oldAm && !empty($oldAm['whatsapp_number'])) {
        sendWhatsAppReply($oldAm['whatsapp_number'], "Heads up — the lead \"{$lead['name']}\" was reassigned to {$nextAM['name']} after {$timeoutHours}h with no action.");
    }
    if (!empty($nextAM['id'])) {
        $newAmStmt = $pdo->prepare("SELECT whatsapp_number FROM team_members WHERE id = :id LIMIT 1");
        $newAmStmt->execute([':id' => $nextAM['id']]);
        $newPhone = $newAmStmt->fetchColumn();
        if ($newPhone) {
            sendWhatsAppReply($newPhone, "New lead assigned to you: \"{$lead['name']}\" ({$lead['phone']}). It's your turn in rotation — take a look when you can.");
        }
    }

    $reassigned++;
}

echo json_encode(['checked' => count($overdueLeads), 'reassigned' => $reassigned]);
