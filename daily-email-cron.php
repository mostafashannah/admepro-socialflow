<?php
/**
 * daily-email-cron.php — sends the "Daily Performance Summary" email
 * (configured in Settings > Daily Email) to every eligible team member,
 * once per day at the configured send_time/timezone.
 *
 * Nothing previously sent this email automatically — the Settings page
 * only let you configure it and manually "Send Test" / "Send to All".
 * This script is the missing scheduled job.
 *
 * Setup: run every 5 minutes so the configured send_time is caught
 * promptly, e.g. crontab entry:
 *   (star)/5 (star) (star) (star) (star) /usr/bin/php /var/www/socialflow/daily-email-cron.php >> /var/www/socialflow/daily-email-cron.log 2>&1
 * (i.e. 5-minute wildcard cron syntax)
 */

// CLI-only — this script performs real writes (emails, DB records) and has
// no authentication of its own, so it must never be reachable over plain
// HTTP (this file sits in the public web root alongside the app).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$es = $pdo->query("SELECT * FROM email_settings WHERE setting_key = 'daily_performance_email' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$es || !$es['enabled']) { echo json_encode(['sent'=>false,'reason'=>'disabled_or_missing']); exit; }

$tz = new DateTimeZone($es['timezone'] ?: 'Africa/Cairo');
$now = new DateTime('now', $tz);
$today = $now->format('Y-m-d');
$nowHM = $now->format('H:i');

// Already sent today? Skip (idempotent across the every-5-min cron ticks).
if ($es['last_sent_at']) {
    $lastSent = new DateTime($es['last_sent_at']);
    $lastSent->setTimezone($tz);
    if ($lastSent->format('Y-m-d') === $today) {
        echo json_encode(['sent'=>false,'reason'=>'already_sent_today']);
        exit;
    }
}

// Only fire once we've reached (or just passed) the configured send time.
$sendTime = $es['send_time'] ?: '18:00';
if ($nowHM < $sendTime) { echo json_encode(['sent'=>false,'reason'=>'not_time_yet','now'=>$nowHM,'send_time'=>$sendTime]); exit; }

$app = $pdo->query("SELECT * FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$accent = $app['primary_color'] ?: '#d90b2c';
$appName = $app['app_name'] ?: 'SocialFlow';
$tagline = $app['agency_tagline'] ?: 'Social Media Agency';

$team = $pdo->query("SELECT * FROM team_members WHERE status = 'active' AND role NOT IN ('client','accountant')")->fetchAll(PDO::FETCH_ASSOC);
$posts = $pdo->query("SELECT id, title, platform, stage, assigned_to, scheduled_date FROM posts")->fetchAll(PDO::FETCH_ASSOC);
$timeLogs = $pdo->query("SELECT logged_by, duration_minutes FROM time_logs")->fetchAll(PDO::FETCH_ASSOC);
$perfLogs = $pdo->query("SELECT user_email, quality_score FROM performance_logs")->fetchAll(PDO::FETCH_ASSOC);

$MOTIVATIONAL_MESSAGES = [
    "Every completed task brings you closer to excellence. Keep going!",
    "Your dedication today builds the success of tomorrow. Well done!",
    "Great work this week! Consistency is the key to extraordinary results.",
    "Small progress every day adds up to big results. You're doing amazing!",
    "The team is proud of your efforts. Keep up the fantastic work!",
];

function section_header($title, $acc) {
    return "<tr><td style=\"padding:24px 32px 8px\">
      <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>
        <td style=\"border-bottom:2px solid $acc;padding-bottom:8px\">
          <span style=\"font-size:18px;font-weight:800;color:$acc;font-family:Arial,sans-serif\">$title</span>
        </td>
      </tr></table>
    </td></tr>";
}

function metric_card($label, $value, $color) {
    return "<td width=\"25%\" style=\"padding:6px\">
      <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:{$color}11;border:1px solid {$color}33;border-radius:10px\">
        <tr><td style=\"padding:14px;text-align:center\">
          <div style=\"font-size:26px;font-weight:800;color:$color;font-family:Arial,sans-serif;line-height:1\">$value</div>
          <div style=\"font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.05em;margin-top:4px;font-family:Arial,sans-serif\">$label</div>
        </td></tr>
      </table>
    </td>";
}

function task_row($task, $color) {
    $title = htmlspecialchars($task['title'] ?? '');
    $platform = htmlspecialchars($task['platform'] ?? '');
    $stage = htmlspecialchars(str_replace('_', ' ', $task['stage'] ?? ''));
    return "<tr><td style=\"padding:8px 32px\">
      <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f8f8f8;border-radius:8px;border-left:3px solid $color\">
        <tr><td style=\"padding:10px 14px\">
          <span style=\"font-size:13px;font-weight:600;color:#1a1a1a;font-family:Arial,sans-serif\">$title</span>
          <span style=\"font-size:11px;color:#888;margin-left:8px;font-family:Arial,sans-serif\">$platform · $stage</span>
        </td></tr>
      </table>
    </td></tr>";
}

function resolve_subject($subj, $tz) {
    $today = (new DateTime('now', $tz))->format('d M Y');
    return str_ireplace('{date}', $today, $subj ?: 'Your Daily Performance Report');
}

function generate_email_html($es, $member, $posts, $timeLogs, $perfLogs, $accent, $appName, $tagline, $motiv) {
    $memberEmail = $member['email'];
    $assigned = array_values(array_filter($posts, fn($p) => $p['assigned_to'] === $memberEmail));
    $completed = array_values(array_filter($assigned, fn($p) => in_array($p['stage'], ['published','scheduled'])));
    $pending = array_values(array_filter($assigned, fn($p) => !in_array($p['stage'], ['published','scheduled','rejected'])));
    $overdue = array_values(array_filter($assigned, fn($p) => !empty($p['scheduled_date']) && strtotime($p['scheduled_date']) < time() && !in_array($p['stage'], ['published','rejected'])));
    $myLogs = array_values(array_filter($timeLogs, fn($t) => $t['logged_by'] === $memberEmail));
    $totalHrs = array_sum(array_map(fn($t) => ($t['duration_minutes'] ?? 0) / 60, $myLogs));
    $myPerfLogs = array_values(array_filter($perfLogs, fn($l) => $l['user_email'] === $memberEmail));
    $avgQ = count($myPerfLogs) ? array_sum(array_column($myPerfLogs, 'quality_score')) / count($myPerfLogs) : 0;
    // Matches the app's Performance page (computePerformance in app.jsx): a
    // member with zero PerformanceLog rows has no real quality data yet, so
    // the score is 0 rather than a completed-tasks-only number that would
    // disagree with what the system itself shows for that same person.
    $score = count($myPerfLogs) ? min(100, round(count($completed) * 18 + $avgQ * 0.4)) : 0;
    $today = date('l, F j, Y');
    $firstName = explode(' ', $member['name'])[0];
    $acc = $accent;

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f0f0f0;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f0;padding:30px 0">
  <tr><td align="center">
  <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.1)">

    <tr><td style="background:'.$acc.';padding:32px">
      <table width="100%" cellpadding="0" cellspacing="0"><tr>
        <td>
          <div style="font-size:22px;font-weight:800;color:#fff;font-family:Arial,sans-serif">'.htmlspecialchars($appName).'</div>
          <div style="font-size:12px;color:rgba(255,255,255,0.7);margin-top:2px;font-family:Arial,sans-serif">'.htmlspecialchars($tagline).'</div>
        </td>
        <td align="right">
          <div style="font-size:13px;color:rgba(255,255,255,0.8);font-family:Arial,sans-serif">'.$today.'</div>
        </td>
      </tr></table>
    </td></tr>

    <tr><td style="padding:28px 32px 16px">
      <div style="font-size:22px;font-weight:800;color:#1a1a1a;font-family:Arial,sans-serif">Hi '.htmlspecialchars($firstName).'!</div>
      <div style="font-size:14px;color:#666;margin-top:6px;line-height:1.6;font-family:Arial,sans-serif">Here\'s your daily performance summary. Let\'s see how your day went.</div>
    </td></tr>';

    if ($es['include_metrics']) {
        $html .= section_header('Summary Metrics', $acc);
        $html .= '<tr><td style="padding:12px 26px"><table width="100%" cellpadding="0" cellspacing="0"><tr>'
            . metric_card('Completed', count($completed), '#10b981')
            . metric_card('Pending', count($pending), '#f59e0b')
            . metric_card('Overdue', count($overdue), count($overdue)>0?'#ef4444':'#6b7280')
            . metric_card('Score', $score, $acc)
            . '</tr></table></td></tr>';
    }

    if ($es['include_working_hours']) {
        $html .= section_header('Working Hours', $acc);
        $avgPerTask = count($myLogs) ? round($totalHrs / count($myLogs) * 10) / 10 : 0;
        $html .= '<tr><td style="padding:12px 32px">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:'.$acc.'11;border-radius:10px;border:1px solid '.$acc.'33">
        <tr>
          <td style="padding:18px 24px;text-align:center">
            <div style="font-size:36px;font-weight:800;color:'.$acc.';font-family:Arial,sans-serif">'.(round($totalHrs*10)/10).'h</div>
            <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.05em;font-family:Arial,sans-serif">Total Logged</div>
          </td>
          <td style="padding:18px 24px;text-align:center;border-left:1px solid '.$acc.'22">
            <div style="font-size:36px;font-weight:800;color:#10b981;font-family:Arial,sans-serif">'.count($myLogs).'</div>
            <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.05em;font-family:Arial,sans-serif">Log Entries</div>
          </td>
          <td style="padding:18px 24px;text-align:center;border-left:1px solid '.$acc.'22">
            <div style="font-size:36px;font-weight:800;color:#8b5cf6;font-family:Arial,sans-serif">'.$avgPerTask.'h</div>
            <div style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.05em;font-family:Arial,sans-serif">Avg / Task</div>
          </td>
        </tr>
      </table>
    </td></tr>';
    }

    if ($es['include_completed_tasks'] && count($completed) > 0) {
        $html .= section_header('Completed Tasks', $acc);
        foreach (array_slice($completed, 0, 5) as $t) $html .= task_row($t, '#10b981');
        if (count($completed) > 5) $html .= '<tr><td style="padding:4px 32px 8px"><span style="font-size:12px;color:#888;font-family:Arial,sans-serif">+'.(count($completed)-5).' more completed tasks</span></td></tr>';
    }

    if ($es['include_pending_tasks'] && count($pending) > 0) {
        $html .= section_header('Pending Tasks', $acc);
        foreach (array_slice($pending, 0, 5) as $t) $html .= task_row($t, '#f59e0b');
        if (count($pending) > 5) $html .= '<tr><td style="padding:4px 32px 8px"><span style="font-size:12px;color:#888;font-family:Arial,sans-serif">+'.(count($pending)-5).' more pending</span></td></tr>';
    }

    if ($es['include_overdue_tasks'] && count($overdue) > 0) {
        $html .= section_header('Overdue Tasks', $acc);
        foreach (array_slice($overdue, 0, 5) as $t) $html .= task_row($t, '#ef4444');
    }

    if ($es['include_motivation']) {
        $html .= '<tr><td style="padding:24px 32px">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(135deg,'.$acc.'22,'.$acc.'08);border-radius:12px;border:1px solid '.$acc.'33">
        <tr><td style="padding:20px 24px;text-align:center">
          <div style="font-size:14px;color:#333;line-height:1.7;font-family:Arial,sans-serif;font-style:italic">"'.htmlspecialchars($motiv).'"</div>
        </td></tr>
      </table>
    </td></tr>';
    }

    $html .= '<tr><td style="background:#f8f8f8;padding:20px 32px;border-top:1px solid #eee">
      <div style="font-size:12px;color:#aaa;font-family:Arial,sans-serif;text-align:center;line-height:1.7">
        '.htmlspecialchars($es['custom_footer'] ?? '').'<br/>
        Sent by '.htmlspecialchars($es['sender_name'] ?? '').' · '.htmlspecialchars($es['sender_email'] ?? '').'<br/>
        <span style="font-size:10px">This is an automated daily performance report from '.htmlspecialchars($appName).'</span>
      </div>
    </td></tr>

  </table>
  </td></tr>
</table>
</body></html>';

    return $html;
}

function send_via_resend($to, $subject, $html, $fromName) {
    $payload = json_encode([
        "from"    => "$fromName <noreply@admepro.com>",
        "to"      => [$to],
        "subject" => $subject,
        "html"    => $html,
    ]);
    $ch = curl_init("https://api.resend.com/emails");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer " . RESEND_API_KEY,
            "Content-Type: application/json",
        ],
    ]);
    $res    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status >= 200 && $status < 300, $res];
}

$insertLog = $pdo->prepare("INSERT INTO email_logs (id, `to`, subject, from_name, status, error_message, sent_at) VALUES (UUID(), :to, :subject, :from_name, :status, :error_message, :sent_at)");

$motiv = $MOTIVATIONAL_MESSAGES[array_rand($MOTIVATIONAL_MESSAGES)];
$subject = resolve_subject($es['subject'], $tz);
$sentCount = 0;

foreach ($team as $member) {
    if (!$member['email']) continue;
    $html = generate_email_html($es, $member, $posts, $timeLogs, $perfLogs, $accent, $appName, $tagline, $motiv);
    [$ok, $raw] = send_via_resend($member['email'], $subject, $html, $es['sender_name'] ?: 'SocialFlow');
    $insertLog->execute([
        ':to' => $member['email'], ':subject' => $subject, ':from_name' => $es['sender_name'] ?: 'SocialFlow',
        ':status' => $ok ? 'sent' : 'failed', ':error_message' => $ok ? '' : $raw,
        ':sent_at' => $now->format('Y-m-d H:i:s'),
    ]);
    if ($ok) $sentCount++;
}

$pdo->prepare("UPDATE email_settings SET last_sent_at = :ts, last_sent_count = :cnt WHERE setting_key = 'daily_performance_email'")
    ->execute([':ts' => gmdate('Y-m-d\TH:i:s\Z'), ':cnt' => $sentCount]);

header('Content-Type: application/json');
echo json_encode(['sent'=>true,'checked'=>count($team),'sent_count'=>$sentCount]);
