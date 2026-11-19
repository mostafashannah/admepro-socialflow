<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script
// ================================================================
// Daily check of every connected Facebook / Instagram account's access token.
//   - Instagram Login tokens (60 days): refreshed automatically once they are >24h old,
//     so they never expire while the account keeps being used.
//   - Facebook Page tokens: checked with Meta's debug_token (valid? expires when?).
//   - If a token is dead or expires within 7 days, admins get ONE WhatsApp line per
//     connection every 3 days ("reconnect Bino's Instagram"), and it is written to the activity log.
// Crontab (server):   30 6 * * * php /var/www/socialflow/token-health-cron.php
// Manual run:         php token-health-cron.php            (prints a report, no WhatsApp with 'dry')
//                     php token-health-cron.php resend     (sends the current warnings to the account managers / directors now, ignoring the 3-day wait; admins are not messaged again)
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php'; // sendWhatsAppReply()
require_once __DIR__ . '/publish-alert-lib.php'; // paRecipients(): the client's account manager + account directors

$dry = (($argv[1] ?? '') === 'dry');
$resend = (($argv[1] ?? '') === 'resend');
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// The app's DB user can't CREATE tables: run migration-token-health.sql once as the MySQL admin.
$haveTable = true;
try { $pdo->query("SELECT 1 FROM token_health LIMIT 0"); } catch (Throwable $e) { $haveTable = false; echo "NOTE: table token_health is missing — run: mysql -u root -p socialflow < /var/www/socialflow/migration-token-health.sql\n(the check still runs; alerts won't be de-duplicated until then)\n\n"; }

function th_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true) ?: [];
}

$rows = $pdo->query("SELECT id, app_key, client_id, client_name, credentials FROM integrations WHERE status = 'active' AND app_key IN ('facebook','instagram')")->fetchAll(PDO::FETCH_ASSOC);
$report = []; $problems = [];
$save = $haveTable ? $pdo->prepare("INSERT INTO token_health (integration_id, checked_at, state, expires_at, note) VALUES (?, NOW(), ?, ?, ?)
  ON DUPLICATE KEY UPDATE checked_at = NOW(), state = VALUES(state), expires_at = VALUES(expires_at), note = VALUES(note)") : null;

foreach ($rows as $r) {
    $creds = json_decode($r['credentials'] ?? '{}', true) ?: [];
    $tok = trim((string)($creds['access_token'] ?? ''));
    $label = ($r['client_name'] ?: 'unnamed') . ' ' . ucfirst($r['app_key']);
    if ($tok === '') { $state = 'no_token'; $exp = null; $note = 'No access token saved'; }
    elseif ($r['app_key'] === 'instagram') {
        // Refresh (works on a still-valid token that is at least 24h old); a failure means expired/revoked.
        $res = th_get('https://graph.instagram.com/refresh_access_token?' . http_build_query(['grant_type' => 'ig_refresh_token', 'access_token' => $tok]));
        if (!empty($res['access_token'])) {
            $exp = date('Y-m-d H:i:s', time() + (int)($res['expires_in'] ?? 5184000));
            if (!$dry) {
                $creds['access_token'] = $res['access_token'];
                $pdo->prepare("UPDATE integrations SET credentials = ? WHERE id = ?")->execute([json_encode($creds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['id']]);
            }
            $state = 'ok'; $note = 'Refreshed — valid ~60 more days';
        } else {
            $msg = (string)($res['error']['message'] ?? 'refresh failed');
            // "too recently refreshed" style answers are not a dead token — fall back to a validity probe.
            $probe = th_get('https://graph.instagram.com/me?' . http_build_query(['fields' => 'id', 'access_token' => $tok]));
            if (!empty($probe['id'])) { $state = 'ok'; $exp = null; $note = 'Valid (refresh not due yet)'; }
            else { $state = 'dead'; $exp = null; $note = mb_substr($msg, 0, 200); }
        }
    } else {
        $appTok = META_APP_ID . '|' . META_APP_SECRET;
        $res = th_get('https://graph.facebook.com/debug_token?' . http_build_query(['input_token' => $tok, 'access_token' => $appTok]));
        $d = $res['data'] ?? null;
        if (!$d) { $state = 'unknown'; $exp = null; $note = mb_substr((string)($res['error']['message'] ?? 'no answer from Meta'), 0, 200); }
        elseif (empty($d['is_valid'])) { $state = 'dead'; $exp = null; $note = mb_substr((string)($d['error']['message'] ?? 'token invalid'), 0, 200); }
        else {
            $e = (int)($d['expires_at'] ?? 0);
            $exp = $e > 0 ? date('Y-m-d H:i:s', $e) : null;
            $state = ($e > 0 && $e < time() + 7 * 86400) ? 'expiring' : 'ok';
            $note = $e > 0 ? 'Expires ' . date('j M', $e) : 'Does not expire';
        }
    }
    if (!$dry && $save) $save->execute([$r['id'], $state, $exp, $note]);
    $report[] = sprintf('%-34s %-9s %s', $label, $state, $note);
    if (in_array($state, ['dead', 'no_token', 'expiring'], true)) $problems[] = ['id' => $r['id'], 'client_id' => $r['client_id'], 'label' => $label, 'state' => $state, 'note' => $note, 'exp' => $exp];
}
echo implode("\n", $report) . "\n";

if (!$problems || $dry) { echo $problems ? "(dry run — no messages sent)\n" : "All connections healthy.\n"; exit; }

// One alert per connection every 3 days.
$due = [];
$last = $haveTable ? $pdo->prepare("SELECT last_alert_at FROM token_health WHERE integration_id = ?") : null;
foreach ($problems as $p) {
    $la = null; if ($last) { $last->execute([$p['id']]); $la = $last->fetchColumn(); }
    if (!$la || strtotime($la) < time() - 3 * 86400) $due[] = $p;
}
if ($resend) $due = $problems;   // resend: everything that is wrong right now
if (!$due) { echo "Problems already alerted recently.\n"; exit; }
$lines = array_map(fn($p) => '• ' . $p['label'] . ($p['state'] === 'expiring' ? ' — expires ' . date('j M', strtotime($p['exp'])) : ' — needs reconnecting'), $due);
$msg = "🔌 Social connections need attention:\n" . implode("\n", $lines) . "\nReconnect in SocialFlow → Integrations.";
$admins = $pdo->query("SELECT whatsapp_number FROM team_members WHERE role = 'admin' AND status = 'active' AND whatsapp_number IS NOT NULL AND whatsapp_number <> ''")->fetchAll(PDO::FETCH_COLUMN);
if (!$resend) foreach ($admins as $num) { try { sendWhatsAppReply($num, $msg); } catch (Throwable $e) { error_log('[token-health] whatsapp: ' . $e->getMessage()); } }
// The people who answer for each client (account manager + account directors) get the connections that affect them.
$adminPhones = array_map(fn($n) => preg_replace('/\D/', '', (string)$n), $admins);
$byPerson = [];
foreach ($due as $p) {
    foreach (paRecipients($pdo, $p['client_id'] ?? '') as $email => $r) { $byPerson[$email]['r'] = $r; $byPerson[$email]['lines'][] = '• ' . $p['label'] . ($p['state'] === 'expiring' ? ' — expires ' . date('j M', strtotime($p['exp'])) : ' — needs reconnecting'); }
}
$teamSent = 0;
foreach ($byPerson as $email => $d) {
    if (in_array(preg_replace('/\D/', '', (string)$d['r']['phone']), $adminPhones, true)) continue;   // admins already got the full list
    try { sendWhatsAppReply($d['r']['phone'], "🔌 Social connections need attention (posts cannot publish to them until fixed):\n" . implode("\n", array_unique($d['lines'])) . "\nReconnect in SocialFlow → Integrations."); $teamSent++; } catch (Throwable $e) { error_log('[token-health] team whatsapp: ' . $e->getMessage()); }
}
$mark = $haveTable ? $pdo->prepare("UPDATE token_health SET last_alert_at = NOW() WHERE integration_id = ?") : null;
$log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'Connection needs attention', 'integrations', ?, 'warning', 'cron')");
foreach ($due as $p) { if ($mark) $mark->execute([$p['id']]); $log->execute([$p['label'] . ': ' . $p['note']]); }
echo ($resend ? "Resent to " : "Alerted " . count($admins) . " admin(s) and ") . $teamSent . " account manager(s)/director(s) about " . count($due) . " connection(s).\n";
