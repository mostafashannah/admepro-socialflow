<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
// ================================================================
// WhatsApp messages about publishing, sent by auto-publish.php to the people who answer for the client:
//   - the client's account manager + every active account director (+ PUBLISH_ALERT_EXTRA_EMAILS from config.php, comma separated)
//   1) ✅ a post went out on all its platforms
//   2) ⚠️ the auto-publisher gave up on a post (3 failed attempts), with the reason in plain words
//   3) ⚠️ once a day (from 10:00 Cairo): posts that can never publish by themselves (no date / no platform / X caption too long /
//      marked Published but missing a platform)
// Every function is best-effort: a failed message must never stop publishing.
// ================================================================
require_once __DIR__ . '/pro-lib.php'; // sendWhatsAppReply()

function paPlatformName($p): string { return ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'twitter' => 'X', 'tiktok' => 'TikTok', 'website' => 'Website'][strtolower((string)$p)] ?? ucfirst((string)$p); }

/** An error from a platform, in plain words with what to do. */
function paFriendly($platform, $err): string {
    $s = is_string($err) ? $err : json_encode($err, JSON_UNESCAPED_UNICODE);
    $name = paPlatformName($platform);
    if (preg_match('/limited to 280 characters.*?(\d{3})/i', $s, $m)) return "the X text is too long ({$m[1]} characters, the limit is 280). Add a short X caption.";
    if (preg_match('/"code":\s*190|session (has expired|has been invalidated)|error validating access token|token (has )?expired/i', $s)) return "the {$name} connection expired. Reconnect it in Integrations.";
    if (strpos($s, '1366046') !== false) return "{$name} could not read the image (too large or an unsupported format).";
    if (stripos($s, 'Reel processing timed out') !== false) return "{$name} was still processing the video when the wait ended. Try a smaller or shorter file.";
    if (stripos($s, 'rejected the reel video') !== false) return "{$name} rejected the video file. Re-export it vertical 9:16, H.264 + AAC, under 90 seconds.";
    if (stripos($s, 'requires a video') !== false) return "{$name} needs a video file on this post.";
    if (stripos($s, 'privacy') !== false && $platform === 'tiktok') return "choose a default TikTok visibility in Integrations → TikTok.";
    return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($s))), 0, 140);
}

/** Is there an active connection the auto-publisher could use for this platform and client? (same lookup auto-publish.php does) */
function paHasIntegration(PDO $pdo, string $platform, $clientId): bool {
    static $cache = [];
    $k = $platform . '|' . $clientId; if (isset($cache[$k])) return $cache[$k];
    $q = $pdo->prepare("SELECT 1 FROM integrations WHERE app_key = :p AND status = 'active' AND (client_id = :c OR client_id IS NULL OR client_id = '') LIMIT 1");
    $q->execute([':p' => $platform, ':c' => (string)$clientId]);
    return $cache[$k] = (bool)$q->fetchColumn();
}

/** [email => ['name','phone']] : the client's account manager, all account directors, plus the extra emails from config. */
function paRecipients(PDO $pdo, $clientId): array {
    $out = [];
    $add = function (array $r) use (&$out) { if (!empty($r['whatsapp_number']) && !empty($r['email'])) $out[strtolower($r['email'])] = ['name' => $r['name'], 'phone' => $r['whatsapp_number']]; };
    if ($clientId) {
        $q = $pdo->prepare("SELECT tm.name, tm.email, tm.whatsapp_number FROM clients c JOIN team_members tm ON tm.id = c.account_manager_id WHERE c.id = :c AND tm.status = 'active'");
        $q->execute([':c' => $clientId]); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r);
    }
    foreach ($pdo->query("SELECT name, email, whatsapp_number FROM team_members WHERE role = 'account_director' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r);
    if (defined('PUBLISH_ALERT_EXTRA_EMAILS')) {
        $extra = array_filter(array_map('trim', is_array(PUBLISH_ALERT_EXTRA_EMAILS) ? PUBLISH_ALERT_EXTRA_EMAILS : explode(',', (string)PUBLISH_ALERT_EXTRA_EMAILS)));
        if ($extra) {
            $in = implode(',', array_fill(0, count($extra), '?'));
            $q = $pdo->prepare("SELECT name, email, whatsapp_number FROM team_members WHERE LOWER(email) IN ($in) AND status = 'active'");
            $q->execute(array_map('strtolower', $extra)); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r);
        }
    }
    return $out;
}

function paSend(PDO $pdo, array $recipients, string $text): void {
    $prefs = $pdo->prepare("SELECT all_disabled FROM notification_prefs WHERE user_email = :e LIMIT 1");
    foreach ($recipients as $email => $r) {
        try {
            $prefs->execute([':e' => $email]); if ($prefs->fetchColumn()) continue;   // they switched all notifications off
            sendWhatsAppReply($r['phone'], mb_strlen($text) > 900 ? mb_substr($text, 0, 890) . '…' : $text);
        } catch (Throwable $e) { error_log('[publish-alert] send failed: ' . $e->getMessage()); }
    }
}

function paNotifyPublished(PDO $pdo, array $post, array $platforms): void {
    try {
        $list = implode(', ', array_map('paPlatformName', array_values(array_unique($platforms))));
        paSend($pdo, paRecipients($pdo, $post['client_id'] ?? ''), "✅ Published: {$post['client_name']} · \"" . trim((string)$post['title']) . "\" is live on {$list}.");
    } catch (Throwable $e) { error_log('[publish-alert] ' . $e->getMessage()); }
}

function paNotifyGaveUp(PDO $pdo, array $post, array $errorsByPlatform, array $donePlatforms = []): void {
    try {
        $lines = [];
        foreach ($errorsByPlatform as $pl => $err) $lines[] = '• ' . paPlatformName($pl) . ': ' . paFriendly($pl, $err);
        if (!$lines) return;
        $ok = $donePlatforms ? "\nAlready live on: " . implode(', ', array_map('paPlatformName', $donePlatforms)) . '.' : '';
        paSend($pdo, paRecipients($pdo, $post['client_id'] ?? ''), "⚠️ Could not publish: {$post['client_name']} · \"" . trim((string)$post['title']) . "\"\nTried 3 times and stopped:\n" . implode("\n", $lines) . $ok . "\nFix it, then tell the team to put the post back in Scheduled.");
    } catch (Throwable $e) { error_log('[publish-alert] ' . $e->getMessage()); }
}

/** Once a day from 10:00 Cairo: posts that need a person. One message per recipient. */
function paDailyDigest(PDO $pdo): void {
    try {
        if ((int)date('G') < 10) return;
        $today = date('Y-m-d');
        $seen = $pdo->prepare("SELECT v FROM seo_settings WHERE k = 'publish_alert_daily'"); $seen->execute();
        if ($seen->fetchColumn() === $today) return;
        $pdo->prepare("INSERT INTO seo_settings (k, v) VALUES ('publish_alert_daily', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$today]);

        $posts = $pdo->query("SELECT id, title, client_id, client_name, stage, platform, platforms, scheduled_date, publish_attempts, publish_error, published_platforms, x_caption, caption, hashtags
            FROM posts WHERE (stage = 'scheduled' AND (task_type IS NULL OR task_type <> 'grid_layout'))
               OR (stage = 'published' AND published_at >= NOW() - INTERVAL 7 DAY)")->fetchAll(PDO::FETCH_ASSOC);
        $byPerson = [];
        foreach ($posts as $p) {
            $all = json_decode((string)$p['platforms'], true) ?: [];
            if (!$all && $p['platform']) $all = [strtolower(trim($p['platform']))];
            $done = array_map('strtolower', json_decode((string)$p['published_platforms'], true) ?: []);
            $all = array_values(array_unique(array_map('strtolower', array_map('trim', $all))));
            $why = [];
            if ($p['stage'] === 'scheduled') {
                if (trim((string)$p['scheduled_date']) === '') $why[] = 'no scheduled date, so it never publishes by itself';
                if (!$all) $why[] = 'no platform selected';
                if ((int)$p['publish_attempts'] >= 3) { $e = json_decode((string)$p['publish_error'], true); $why[] = 'stopped after 3 failed attempts' . (is_array($e) && $e ? ' (' . implode('; ', array_map(fn($k, $v) => paPlatformName($k) . ': ' . paFriendly($k, $v), array_keys($e), $e)) . ')' : ''); }
                if (trim((string)$p['scheduled_date']) !== '') foreach ($all as $pl) {
                    // A platform with no connected account can never go out, so the post would stay in Scheduled forever.
                    if (!in_array($pl, $done, true) && in_array($pl, ['facebook', 'instagram', 'linkedin', 'tiktok', 'twitter', 'website'], true) && !paHasIntegration($pdo, $pl, $p['client_id'] ?? ''))
                        $why[] = 'no ' . paPlatformName($pl) . ' account is connected, so it can never be sent there (connect it, or remove ' . paPlatformName($pl) . ' from the post)';
                }
                if ((int)$p['publish_attempts'] < 3 && in_array('twitter', $all, true) && !in_array('twitter', $done, true)) {
                    $x = trim((string)$p['x_caption']) !== '' ? trim($p['x_caption']) : trim(trim((string)$p['caption']) . "\n\n" . trim((string)$p['hashtags']));
                    if (mb_strlen($x) > 280) $why[] = 'the X text is ' . mb_strlen($x) . ' characters (limit 280): add a short X caption';
                }
            } else {
                $missing = array_values(array_diff($all, $done));
                if ($missing) $why[] = 'marked Published but never sent to ' . implode(', ', array_map('paPlatformName', $missing));
            }
            if (!$why) continue;
            $line = "• {$p['client_name']} · \"" . trim((string)$p['title']) . '": ' . implode('; ', $why);
            foreach (paRecipients($pdo, $p['client_id'] ?? '') as $email => $r) { $byPerson[$email]['r'] = $r; $byPerson[$email]['lines'][] = $line; }
        }
        foreach ($byPerson as $email => $d) {
            $n = count($d['lines']); $shown = array_slice($d['lines'], 0, 6);
            paSend($pdo, [$email => $d['r']], "⚠️ {$n} post" . ($n === 1 ? '' : 's') . " need" . ($n === 1 ? 's' : '') . " attention before they can publish:\n" . implode("\n", $shown) . ($n > 6 ? "\n…and " . ($n - 6) . " more (see Scheduled in SocialFlow)." : ''));
        }
    } catch (Throwable $e) { error_log('[publish-alert] daily digest: ' . $e->getMessage()); }
}
