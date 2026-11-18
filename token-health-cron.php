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
// ================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php'; // sendWhatsAppReply()

$dry = (($argv[1] ?? '') === 'dry');
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE IF NOT EXISTS token_health (
  integration_id CHAR(36) NOT NULL PRIMARY KEY,
  checked_at DATETIME NOT NULL,
  state VARCHAR(20) NOT NULL,
  expires_at DATETIME NULL,
  note VARCHAR(255) NULL,
  last_alert_at DATETIME NULL
)");

function th_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true) ?: [];
}

$rows = $pdo->query("SELECT id, app_key, client_name, credentials FROM integrations WHERE status = 'active' AND app_key IN ('facebook','instagram')")->fetchAll(PDO::FETCH_ASSOC);
$report = []; $problems = [];
$save = $pdo->prepare("INSERT INTO token_health (integration_id, checked_at, state, expires_at, note) VALUES (?, NOW(), ?, ?, ?)
  ON DUPLICATE KEY UPDATE checked_at = NOW(), state = VALUES(state), expires_at = VALUES(expires_at), note = VALUES(note)");

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
    if (!$dry) $save->execute([$r['id'], $state, $exp, $note]);
    $report[] = sprintf('%-34s %-9s %s', $label, $state, $note);
    if (in_array($state, ['dead', 'no_token', 'expiring'], true)) $problems[] = ['id' => $r['id'], 'label' => $label, 'state' => $state, 'note' => $note, 'exp' => $exp];
}
echo implode("\n", $report) . "\n";

if (!$problems || $dry) { echo $problems ? "(dry run — no messages sent)\n" : "All connections healthy.\n"; exit; }

// One alert per connection every 3 days.
$due = [];
$last = $pdo->prepare("SELECT last_alert_at FROM token_health WHERE integration_id = ?");
foreach ($problems as $p) {
    $last->execute([$p['id']]); $la = $last->fetchColumn();
    if (!$la || strtotime($la) < time() - 3 * 86400) $due[] = $p;
}
if (!$due) { echo "Problems already alerted recently.\n"; exit; }
$lines = array_map(fn($p) => '• ' . $p['label'] . ($p['state'] === 'expiring' ? ' — expires ' . date('j M', strtotime($p['exp'])) : ' — needs reconnecting'), $due);
$msg = "🔌 Social connections need attention:\n" . implode("\n", $lines) . "\nReconnect in SocialFlow → Integrations.";
$admins = $pdo->query("SELECT whatsapp_number FROM team_members WHERE role = 'admin' AND status = 'active' AND whatsapp_number IS NOT NULL AND whatsapp_number <> ''")->fetchAll(PDO::FETCH_COLUMN);
foreach ($admins as $num) { try { sendWhatsAppReply($num, $msg); } catch (Throwable $e) { error_log('[token-health] whatsapp: ' . $e->getMessage()); } }
$mark = $pdo->prepare("UPDATE token_health SET last_alert_at = NOW() WHERE integration_id = ?");
$log = $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'Connection needs attention', 'integrations', ?, 'warning', 'cron')");
foreach ($due as $p) { $mark->execute([$p['id']]); $log->execute([$p['label'] . ': ' . $p['note']]); }
echo "Alerted " . count($admins) . " admin(s) about " . count($due) . " connection(s).\n";
