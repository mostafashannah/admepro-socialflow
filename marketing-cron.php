<?php
/** marketing-cron.php — run every minute from cron (CLI only). Queues due campaigns and sends in small batches. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/growth-lib.php';
$pdo = gPdo();
$lock = fopen(sys_get_temp_dir() . '/sf-marketing.lock', 'c'); if (!flock($lock, LOCK_EX | LOCK_NB)) exit;
$BATCH = defined('MARKETING_BATCH') ? (int)MARKETING_BATCH : 60; // per minute
$from = defined('MARKETING_FROM') ? MARKETING_FROM : 'admepro <news@admepro.com>';

// 1) due scheduled campaigns → build the queue
foreach ($pdo->query("SELECT * FROM mkt_campaigns WHERE status='scheduled' AND scheduled_at <= NOW()")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $bind = []; $w = gAudienceWhere(json_decode($c['audience'], true) ?: [], $c['channel'], $bind);
    $addr = $c['channel'] === 'whatsapp' ? 'phone' : 'email';
    $pdo->prepare("INSERT IGNORE INTO mkt_sends (campaign_id, contact_id, to_addr) SELECT ?, id, $addr FROM mkt_contacts WHERE $w")->execute(array_merge([$c['id']], $bind));
    $pdo->prepare("UPDATE mkt_campaigns SET status='sending', started_at=NOW() WHERE id=?")->execute([$c['id']]);
}

// 2) send a batch
$sent = 0;
foreach ($pdo->query("SELECT * FROM mkt_campaigns WHERE status='sending' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $st = $pdo->prepare("SELECT s.id sid, s.to_addr, k.* FROM mkt_sends s JOIN mkt_contacts k ON k.id=s.contact_id WHERE s.campaign_id=? AND s.status='queued' LIMIT " . max(1, $BATCH - $sent));
    $st->execute([$c['id']]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        // re-check opt-out right before sending
        $skip = $c['channel'] === 'whatsapp' ? ($r['unsub_wa'] || !$r['wa_opt_in']) : ($r['unsub_email'] || $r['bounced']);
        if ($skip) { $pdo->prepare("UPDATE mkt_sends SET status='failed', error='opted out' WHERE id=?")->execute([$r['sid']]); continue; }
        if ($c['channel'] === 'whatsapp') {
            $params = array_map(fn($p) => gMergeTags($p, $r), json_decode((string)$c['wa_params'], true) ?: []);
            [$ok, $pid, $err] = gSendWaTemplate(ltrim($r['to_addr'], '+'), $c['wa_template'], $c['wa_lang'], $params, $c['wa_header_image'] ?: null);
        } else {
            $html = gRenderEmail($c, $r, $r['sid']);
            $unsub = gBase() . '/marketing-unsub.php?' . http_build_query(['id' => $r['sid'], 's' => gSign('u' . $r['sid'])]);
            [$ok, $pid, $err] = gSendEmail($from, $r['to_addr'], gMergeTags($c['subject'], $r), $html, null, $unsub);
        }
        $pdo->prepare("UPDATE mkt_sends SET status=?, provider_id=?, error=?, sent_at=NOW() WHERE id=?")->execute([$ok ? 'sent' : 'failed', $pid, $err, $r['sid']]);
        $sent++;
        usleep(150000); // stay well under Resend / Meta rate limits
    }
    $left = $pdo->prepare("SELECT COUNT(*) FROM mkt_sends WHERE campaign_id=? AND status='queued'"); $left->execute([$c['id']]);
    if ((int)$left->fetchColumn() === 0) $pdo->prepare("UPDATE mkt_campaigns SET status='sent', finished_at=NOW() WHERE id=?")->execute([$c['id']]);
    if ($sent >= $BATCH) break;
}
