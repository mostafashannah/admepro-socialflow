<?php
/**
 * best-time-refresh-cron.php — weekly cron that recomputes the AI-predicted
 * "best posting time" for every client×platform currently set to Auto mode
 * (Settings → Scheduling → Best Posting Times).
 *
 * Predicts a full per-weekday map (Sat..Fri), not one flat hour — audience
 * behavior genuinely differs by weekday, so Monday's best time is rarely
 * Saturday's. Stored as JSON in client_intelligence.{platform}_best_times_by_day;
 * the existing {platform}_best_time column is kept in sync as a single
 * fallback value (today's weekday's time) for any older code path.
 *
 * Re-running weekly (rather than only once, when the user first flips a
 * platform to Auto) lets the prediction adapt as real engagement patterns
 * shift, instead of going stale forever after the first run.
 *
 * Setup:
 *   Cron command: php /var/www/socialflow/best-time-refresh-cron.php
 *   Schedule: once a week, e.g. 0 5 * * 1  (Monday 05:00)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php'; // reuses callClaude()

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function graph_get($url, $params) {
    $qs = http_build_query($params);
    $ch = curl_init("$url?$qs");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 20]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($res, true)];
}

function insights_resilient($base, array $metrics, array $extraParams) {
    [$code, $resp] = graph_get($base, array_merge($extraParams, ["metric" => implode(",", $metrics)]));
    if ($code === 200 && isset($resp["data"])) return $resp["data"];
    $data = [];
    foreach ($metrics as $m) {
        [$c, $r] = graph_get($base, array_merge($extraParams, ["metric" => $m]));
        if ($c === 200 && !empty($r["data"])) $data = array_merge($data, $r["data"]);
    }
    return $data;
}

function flatten_series(array $arr) {
    $out = [];
    foreach ($arr as $s) { $out[$s['name'] ?? ($s['title'] ?? '?')] = $s['values'][count($s['values'] ?? []) - 1]['value'] ?? null; }
    return $out;
}

$v = defined('META_GRAPH_VERSION') ? META_GRAPH_VERSION : 'v23.0';
$DAYS = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
$PLATFORMS = ['instagram', 'facebook', 'tiktok', 'linkedin'];

$rows = $pdo->query("SELECT * FROM client_intelligence")->fetchAll(PDO::FETCH_ASSOC);
$integrations = $pdo->query("SELECT * FROM integrations WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

$summary = ['checked' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

foreach ($rows as $row) {
    $clientId = $row['client_id'];
    $clientName = $row['client_name'] ?? $clientId;
    $client = $pdo->prepare("SELECT industry FROM clients WHERE id = :id");
    $client->execute([':id' => $clientId]);
    $industry = $client->fetchColumn() ?: 'unspecified';

    foreach ($PLATFORMS as $pl) {
        $mode = $row["{$pl}_time_mode"] ?? 'manual';
        if ($mode !== 'auto') continue;
        $summary['checked']++;

        try {
            // Only Facebook/Instagram (Graph API platforms) have real
            // per-account engagement data to pull — TikTok/LinkedIn "auto"
            // still gets a prediction, just from industry norms alone (no
            // metrics/onlineFollowers signal), same as the app-side flow
            // when no integration is connected.
            $integ = null;
            if (in_array($pl, ['instagram', 'facebook'], true)) {
                foreach ($integrations as $i) {
                    if ($i['app_key'] === $pl && $i['client_id'] === $clientId) { $integ = $i; break; }
                }
                if (!$integ) {
                    foreach ($integrations as $i) {
                        if ($i['app_key'] === $pl && empty($i['client_id'])) { $integ = $i; break; }
                    }
                }
            }

            $metrics = [];
            $onlineFollowers = null;
            if ($integ) {
                $creds = json_decode($integ['credentials'] ?? '{}', true) ?: [];
                $page_id = trim($creds['page_id'] ?? '');
                $access_token = trim($creds['access_token'] ?? '');
                if ($page_id && $access_token) {
                    $igLoginToken = $pl === 'instagram' && str_starts_with($access_token, 'IGAA');
                    $host = $igLoginToken ? 'graph.instagram.com' : 'graph.facebook.com';

                    $since = time() - 30 * 86400;
                    if ($pl === 'facebook') {
                        $series = insights_resilient("https://graph.facebook.com/{$v}/{$page_id}/insights",
                            ['page_post_engagements', 'page_impressions_unique', 'page_fans'],
                            ['period' => 'day', 'since' => $since, 'until' => time(), 'access_token' => $access_token]);
                    } else {
                        $series = insights_resilient("https://{$host}/{$v}/{$page_id}/insights",
                            ['reach', 'profile_views'],
                            ['period' => 'day', 'metric_type' => 'total_value', 'since' => $since, 'until' => time(), 'access_token' => $access_token]);
                    }
                    $metrics = flatten_series($series);

                    if (!$igLoginToken) {
                        [$bc, $bresp] = graph_get("https://graph.facebook.com/{$v}/{$page_id}/insights", [
                            'metric' => 'online_followers', 'period' => 'lifetime', 'access_token' => $access_token,
                        ]);
                        $breakdown = $bresp['data'][0]['values'][0]['value'] ?? null;
                        if ($bc === 200 && $breakdown) $onlineFollowers = $breakdown;
                    }
                }
            }

            $sys = "You are a social media scheduling expert. Given a client's industry and recent platform performance data, "
                . "predict the best hour of day to publish on {$pl} to maximize reach and engagement — separately for EACH day "
                . "of the week, since audience behavior genuinely differs by weekday. Reply with ONLY JSON: "
                . '{"times":{"saturday":"HH:MM","sunday":"HH:MM","monday":"HH:MM","tuesday":"HH:MM","wednesday":"HH:MM","thursday":"HH:MM","friday":"HH:MM"},"reason":"short summary"}'
                . " Use 24h format for every day. If audience-online-hour data is provided, weigh it heavily alongside industry "
                . "norms and the reach/engagement numbers; otherwise rely on general industry posting-time best practices for {$pl}.";
            $userMsg = "Industry: {$industry}\nPlatform: {$pl}\nRecent 30-day metrics: " . json_encode($metrics)
                . ($onlineFollowers ? "\nAudience online-hour breakdown: " . json_encode($onlineFollowers) : '');

            [$status, $data] = callClaude(['model' => 'claude-haiku-4-5-20251001', 'max_tokens' => 400, 'system' => $sys, 'messages' => [['role' => 'user', 'content' => $userMsg]]]);
            $text = '';
            if ($status >= 200 && $status < 300) {
                foreach (($data['content'] ?? []) as $block) { if (($block['type'] ?? '') === 'text') $text .= $block['text']; }
            }
            if (!preg_match('/\{[\s\S]*\}/', $text, $m)) throw new Exception('No prediction returned');
            $parsed = json_decode($m[0], true);
            $times = $parsed['times'] ?? null;
            $valid = $times && !array_filter($DAYS, fn($d) => !preg_match('/^\d{2}:\d{2}$/', $times[$d] ?? ''));
            if (!$valid) throw new Exception('Invalid or incomplete time map returned');

            // date('w'): 0=Sun..6=Sat. Map to $DAYS (starts Saturday) index: Sat=0,Sun=1,Mon=2..Fri=6
            $wIdx = (int) date('w');
            $todayDow = $DAYS[$wIdx === 6 ? 0 : $wIdx + 1];

            $pdo->prepare("UPDATE client_intelligence SET `{$pl}_best_times_by_day` = :map, `{$pl}_best_time` = :flat WHERE client_id = :cid")
                ->execute([':map' => json_encode($times), ':flat' => $times[$todayDow] ?? reset($times), ':cid' => $clientId]);
            $summary['updated']++;
        } catch (Throwable $e) {
            $summary['errors'][] = "{$clientName}/{$pl}: " . $e->getMessage();
            error_log("[best-time-refresh-cron] {$clientName}/{$pl}: " . $e->getMessage());
        }
    }
}

header('Content-Type: application/json');
echo json_encode($summary);
