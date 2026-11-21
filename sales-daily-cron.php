<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // server-only script
// ================================================================
// The daily sales machine. Crontab (server):   0 8 * * 0-4 php /var/www/socialflow/sales-daily-cron.php
// (Sunday–Thursday, 8 AM). Manual run: php sales-daily-cron.php force
// Every day it: syncs leads + clients into the marketing contacts, researches new prospects (if switched on), approves the
// best ones (optional), drafts the 3-email sequences, sends the due emails within the daily cap (only if auto-send is on),
// and WhatsApps the admins a short summary. HARD RULE everywhere: only prospects with an email or mobile number.
// Settings: Growth → Sales agent → Daily machine.
// ================================================================
require_once __DIR__ . '/sales-lib.php';
require_once __DIR__ . '/pro-lib.php'; // sendWhatsAppReply()

$force = (($argv[1] ?? '') === 'force');
$cairo = new DateTime('now', new DateTimeZone('Africa/Cairo'));
if (!$force && in_array($cairo->format('N'), ['5', '6'], true)) { echo "Weekend — skipped.\n"; exit; }
$lock = fopen(sys_get_temp_dir() . '/sf-sales-daily.lock', 'c'); if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
$pdo = gPdo();
function saMachineSettings(PDO $pdo) {
    $d = ['enabled' => false, 'brief' => '', 'country' => 'Egypt', 'count' => 8, 'lang' => 'en', 'auto_approve_score' => 0, 'auto_send' => false, 'daily_cap' => 20];
    try { $v = $pdo->query("SELECT v FROM seo_settings WHERE k='sales_machine'")->fetchColumn(); $j = $v ? json_decode($v, true) : null; } catch (Throwable $e) { $j = null; }
    return is_array($j) ? array_merge($d, $j) : $d;
}
$S = saMachineSettings($pdo);
$sum = ['synced' => gSyncLeadsAndClients($pdo), 'found' => 0, 'skipped' => 0, 'approved' => 0, 'drafted' => 0, 'sent' => 0, 'phone_only' => []];
$saRow = function (array $r) { foreach (['signals', 'sources', 'contacts', 'outreach'] as $k) $r[$k] = json_decode((string)$r[$k], true) ?: []; return $r; };

if ($S['enabled']) {
    // 1) research
    if (mb_strlen($S['brief']) >= 8) {
        $id = bin2hex(random_bytes(8)); $jf = sys_get_temp_dir() . '/sf-sales-job-' . $id . '.json';
        file_put_contents($jf, json_encode(['status' => 'queued', 'step' => 'Starting…', 'brief' => $S['brief'], 'country' => $S['country'], 'count' => (int)$S['count'], 'by' => 'daily-machine', 'started' => time()], JSON_UNESCAPED_UNICODE));
        $php = defined('PHP_CLI_BIN') ? PHP_CLI_BIN : (is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php');
        passthru(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/sales-agent.php') . ' job ' . escapeshellarg($id));
        $j = json_decode((string)@file_get_contents($jf), true) ?: [];
        $sum['found'] = (int)($j['added'] ?? 0); if (($j['status'] ?? '') === 'error') $sum['error'] = (string)($j['error'] ?? '');
        @unlink($jf);
    }
    // 2) auto-approve the best (needs an email or mobile — enforced by saApprove)
    if ((int)$S['auto_approve_score'] > 0) {
        $st = $pdo->prepare("SELECT * FROM sales_prospects WHERE status='new' AND score >= ? ORDER BY score DESC LIMIT 10"); $st->execute([(int)$S['auto_approve_score']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { [$ok] = saApprove($pdo, $saRow($r)); if ($ok) $sum['approved']++; }
    }
    // 3) draft the sequences for prospects that have an email but no drafts yet
    $st = $pdo->query("SELECT * FROM sales_prospects WHERE status IN ('new','approved') AND (outreach IS NULL OR outreach='' OR outreach='[]' OR outreach='{}') ORDER BY (status='approved') DESC, score DESC LIMIT 40");
    $drafted = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($drafted >= 8) break;
        $p = $saRow($r); $idx = null;
        foreach ($p['contacts'] as $i => $c) if (!empty($c['email'])) { $idx = $i; break; }
        if ($idx === null) { if (saReachable($p['contacts'])) $sum['phone_only'][] = $p['company']; continue; }
        [$ok, $out] = saDraftSequence($p, $idx, $S['lang']);
        if ($ok) { $pdo->prepare("UPDATE sales_prospects SET outreach=?, updated_at=? WHERE id=?")->execute([json_encode($out, JSON_UNESCAPED_UNICODE), gNow(), $p['id']]); $drafted++; }
    }
    $sum['drafted'] = $drafted;
    // 3b) introduction emails: one pending draft (Gulf or Egypt template) per prospect with an email. The team reviews them in the app; nothing is sent from here.
    try {
        require_once __DIR__ . '/sales-welcome-lib.php';
        $sum['welcome'] = 0;
        $st = $pdo->query("SELECT * FROM sales_prospects WHERE status IN ('new','approved') ORDER BY created_at DESC, score DESC LIMIT 60");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($sum['welcome'] >= 15) break; if (swCreateDraft($pdo, $saRow($r))) $sum['welcome']++; }
    } catch (Throwable $e) { $sum['welcome_error'] = $e->getMessage(); error_log('[sales-daily] welcome: ' . $e->getMessage()); }
    // 4) send the due emails (only when auto-send is on, and only to prospects an admin approved)
    if ($S['auto_send']) {
        $cap = min((int)$S['daily_cap'], defined('SALES_DAILY_CAP') ? (int)SALES_DAILY_CAP : 40);
        $st = $pdo->query("SELECT * FROM sales_prospects WHERE status IN ('approved','contacted') AND outreach IS NOT NULL AND outreach <> '' ORDER BY score DESC LIMIT 200");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = $saRow($r); $steps = $p['outreach']['steps'] ?? []; if (!$steps) continue;
            $c = $p['contacts'][(int)($p['outreach']['contact'] ?? 0)] ?? null; $to = gEmail($c['email'] ?? ''); if (!$to) continue;
            $sent = $pdo->prepare("SELECT step, sent_at FROM sales_sends WHERE prospect_id=? AND to_email=?"); $sent->execute([$p['id'], $to]);
            $done = []; foreach ($sent->fetchAll(PDO::FETCH_ASSOC) as $x) $done[(int)$x['step']] = strtotime($x['sent_at']);
            $next = null;
            if (!isset($done[1])) $next = 1;
            elseif (!isset($done[2]) && time() - $done[1] >= 3 * 86400) $next = 2;
            elseif (isset($done[2]) && !isset($done[3]) && time() - $done[2] >= 4 * 86400) $next = 3;
            if (!$next) continue;
            $tpl = null; foreach ($steps as $s) if ((int)($s['step'] ?? 0) === $next) { $tpl = $s; break; }
            if (!$tpl) continue;
            [$ok, $err] = saSendStep($pdo, $p, $to, $next, (string)$tpl['subject'], (string)$tpl['body'], 'daily-machine', $cap);
            if ($ok) { $sum['sent']++; usleep(400000); } elseif (str_contains((string)$err, 'Daily limit')) break;
        }
    }
}

// 5) summary to the admins
$lines = ["📈 *Sales machine — " . $cairo->format('D j M') . "*"];
$lines[] = $S['enabled'] ? "New prospects: {$sum['found']} · approved {$sum['approved']} · drafted {$sum['drafted']} · emails sent {$sum['sent']}" . ($S['auto_send'] ? '' : ' (auto-send is off)') : 'The daily machine is switched off (Growth → Sales agent).';
try {
    $w = $pdo->query("SELECT SUM(status='pending') pend, SUM(sent_at IS NOT NULL) sent, SUM(opened_at IS NOT NULL) opened, SUM(clicked_at IS NOT NULL) clicked, SUM(replied_at IS NOT NULL) replied FROM sales_welcome")->fetch(PDO::FETCH_ASSOC);
    if ($w && ((int)$w['pend'] || (int)$w['sent'])) $lines[] = "✉️ Introduction emails: " . (int)$w['pend'] . " waiting for your review · sent {$w['sent']} · opened " . (int)$w['opened'] . " · clicked " . (int)$w['clicked'] . " · replied " . (int)$w['replied'];
} catch (Throwable $e) {}
$lines[] = "Contacts synced from leads/clients: {$sum['synced']}";
if (!empty($sum['error'])) $lines[] = "⚠️ Research problem: " . mb_substr($sum['error'], 0, 160);
if ($sum['phone_only']) $lines[] = "📞 Call manually (phone only): " . implode(', ', array_slice($sum['phone_only'], 0, 6));
$replied = (int)$pdo->query("SELECT COUNT(*) FROM mkt_contacts WHERE FIND_IN_SET('wa-responder', REPLACE(tags, ', ', ',')) AND updated_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
if ($replied) $lines[] = "💬 WhatsApp replies became leads today: $replied";
$msg = implode("\n", $lines); echo $msg . "\n";
if ($S['enabled'] || $force) {
    foreach ($pdo->query("SELECT whatsapp_number FROM team_members WHERE role='admin' AND status='active' AND whatsapp_number IS NOT NULL AND whatsapp_number <> ''")->fetchAll(PDO::FETCH_COLUMN) as $num) {
        try { sendWhatsAppReply($num, $msg); } catch (Throwable $e) { error_log('[sales-daily] whatsapp: ' . $e->getMessage()); }
    }
}
