<?php
/**
 * notification-reminders-cron.php — the missing sender behind three
 * Account > Notifications toggles that previously did nothing:
 *   - Due date reminder      (task_due_soon)
 *   - Subscription renewal   (subscription_renewal)
 *   - Daily Digest           (daily_digest)
 * Each toggle only saved to notification_prefs; nothing ever checked it.
 *
 * Two modes, two crontab entries:
 *   php notification-reminders-cron.php reminders   — every 15 min, fires
 *     task-due-soon + subscription-renewal (idempotent via sent-flags)
 *   php notification-reminders-cron.php digest       — once daily, sends
 *     the per-user task digest to everyone with daily_digest enabled
 *
 * Crontab:
 *   (star)/15 (star) (star) (star) (star) /usr/bin/php /var/www/socialflow/notification-reminders-cron.php reminders >> /var/www/socialflow/notification-reminders-cron.log 2>&1
 *   0 8 (star) (star) (star) /usr/bin/php /var/www/socialflow/notification-reminders-cron.php digest >> /var/www/socialflow/notification-reminders-cron.log 2>&1
 * (5-field cron wildcard syntax — written out here so this docblock comment doesn't break PHP parsing)
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

$mode = $argv[1] ?? 'reminders';
$tz = new DateTimeZone('Africa/Cairo');
$now = new DateTime('now', $tz);
$today = $now->format('Y-m-d');

function email_base($content) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 20px">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb">
  <tr><td style="background:#d90b2c;padding:28px 36px;text-align:center">
    <p style="margin:0;font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.5px">SocialFlow</p>
    <p style="margin:6px 0 0;font-size:12px;color:rgba(255,255,255,0.75)">Social Media Agency Platform</p>
  </td></tr>
  <tr><td style="padding:36px">'.$content.'</td></tr>
  <tr><td style="padding:20px 36px;border-top:1px solid #f3f4f6;text-align:center">
    <p style="margin:0;font-size:12px;color:#9ca3af">SocialFlow &middot; <a href="https://socialflow.admepro.com" style="color:#d90b2c;text-decoration:none">socialflow.admepro.com</a></p>
  </td></tr>
</table>
</td></tr></table>
</body></html>';
}
function email_btn($url, $label) {
    return '<div style="text-align:center;margin:24px 0">
    <a href="'.$url.'" style="display:inline-block;background:#d90b2c;color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:15px">'.$label.'</a>
  </div>';
}
function email_h($text) { return '<h2 style="margin:0 0 8px;font-size:20px;font-weight:800;color:#111827">'.$text.'</h2>'; }
function email_p($text) { return '<p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#4b5563">'.$text.'</p>'; }
function email_card($rows) {
    $trs = '';
    foreach ($rows as $row) {
        [$label, $val, $highlight] = array_pad($row, 3, null);
        $trs .= '<tr style="border-bottom:1px solid #f3f4f6">
      <td style="padding:11px 16px;font-size:12px;color:#6b7280;font-weight:600;white-space:nowrap">'.$label.'</td>
      <td style="padding:11px 16px;font-size:13px;font-weight:700;color:'.($highlight ?: '#111827').';text-align:right">'.$val.'</td>
    </tr>';
    }
    return '<table width="100%" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin:16px 0">'.$trs.'</table>';
}
function email_divider() { return '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0"/>'; }

function send_via_resend($to, $subject, $html) {
    $payload = json_encode([
        "from"    => "SocialFlow <noreply@admepro.com>",
        "to"      => [$to],
        "subject" => $subject,
        "html"    => $html,
    ]);
    $ch = curl_init("https://api.resend.com/emails");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . RESEND_API_KEY, "Content-Type: application/json"],
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $status >= 200 && $status < 300;
    return [$ok, $res];
}

$insertLog = $pdo->prepare("INSERT INTO email_logs (id, `to`, subject, from_name, status, error_message, sent_at) VALUES (UUID(), :to, :subject, 'SocialFlow', :status, :error_message, :sent_at)");
function log_email($insertLog, $to, $subject, $ok, $raw, $now) {
    $insertLog->execute([':to'=>$to, ':subject'=>$subject, ':status'=>$ok?'sent':'failed', ':error_message'=>$ok?'':$raw, ':sent_at'=>$now->format('Y-m-d H:i:s')]);
}

// Loads notification_prefs for one email, falling back to the same
// defaults DEFAULT_NOTIF_PREFS uses in app.jsx.
function get_prefs($pdo, $email) {
    $stmt = $pdo->prepare("SELECT * FROM notification_prefs WHERE user_email = :e");
    $stmt->execute([':e' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $defaults = ['all_disabled'=>0,'mentions_only'=>0,'daily_digest'=>1,'task_due_soon'=>1,'subscription_renewal'=>1];
    return $row ? array_merge($defaults, $row) : $defaults;
}
function pref_allows($prefs, $key) {
    if (!empty($prefs['all_disabled'])) return false;
    if (!empty($prefs['mentions_only'])) return false;
    return !isset($prefs[$key]) || $prefs[$key];
}

$counts = ['task_due_soon'=>0, 'subscription_renewal'=>0, 'daily_digest'=>0];

if ($mode === 'reminders') {
    // ── Task due soon: fires once, the day before scheduled_date ──
    $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
    $posts = $pdo->prepare("SELECT * FROM posts WHERE scheduled_date = :d AND stage NOT IN ('published','rejected') AND (due_reminder_sent IS NULL OR due_reminder_sent = 0)");
    $posts->execute([':d' => $tomorrow]);
    $markSent = $pdo->prepare("UPDATE posts SET due_reminder_sent = 1 WHERE id = :id");
    foreach ($posts->fetchAll(PDO::FETCH_ASSOC) as $post) {
        if (!$post['assigned_to']) continue;
        $member = $pdo->prepare("SELECT * FROM team_members WHERE email = :e");
        $member->execute([':e' => $post['assigned_to']]);
        $m = $member->fetch(PDO::FETCH_ASSOC);
        if (!$m) continue;
        $prefs = get_prefs($pdo, $m['email']);
        if (pref_allows($prefs, 'task_due_soon')) {
            $html = email_base(
                email_h('Task due soon') .
                email_p('Hi <strong>'.htmlspecialchars($m['name']).'</strong>,') .
                email_p('This is a reminder that the following task is due soon.') .
                '<div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:14px 18px;border-radius:0 10px 10px 0;margin:16px 0">
                  <p style="margin:0;font-size:15px;font-weight:800;color:#111827">'.htmlspecialchars($post['title']).'</p>
                  <p style="margin:6px 0 0;font-size:13px;color:#92400e">Due: <strong>'.htmlspecialchars($post['scheduled_date']).'</strong></p>
                </div>' .
                email_card([['Client', htmlspecialchars($post['client_name'] ?: '—')], ['Due', htmlspecialchars($post['scheduled_date']), '#f59e0b']]) .
                email_btn('https://socialflow.admepro.com', 'Open Task')
            );
            [$ok, $raw] = send_via_resend($m['email'], '[SocialFlow] Task due soon: '.$post['title'], $html);
            log_email($insertLog, $m['email'], '[SocialFlow] Task due soon: '.$post['title'], $ok, $raw, $now);
            if ($ok) $counts['task_due_soon']++;
        }
        $markSent->execute([':id' => $post['id']]);
    }

    // ── Subscription renewal: fires once, 7 days before next_payment_date ──
    $in7 = (clone $now)->modify('+7 days')->format('Y-m-d');
    $subs = $pdo->prepare("SELECT * FROM subscriptions WHERE status = 'active' AND next_payment_date = :d AND (reminder_7_sent IS NULL OR reminder_7_sent = 0)");
    $subs->execute([':d' => $in7]);
    $markSubSent = $pdo->prepare("UPDATE subscriptions SET reminder_7_sent = 1 WHERE id = :id");
    $recipients = $pdo->query("SELECT * FROM team_members WHERE status = 'active' AND role IN ('admin','accountant','account_manager')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $sub) {
        foreach ($recipients as $m) {
            $prefs = get_prefs($pdo, $m['email']);
            if (!pref_allows($prefs, 'subscription_renewal')) continue;
            $html = email_base(
                email_h('Subscription renewal reminder') .
                email_p('Hi <strong>'.htmlspecialchars($m['name']).'</strong>,') .
                email_p('A client subscription renews in 7 days.') .
                email_card([
                    ['Client', htmlspecialchars($sub['client_name'] ?: '—')],
                    ['Subscription', htmlspecialchars($sub['service_name'] ?: '—')],
                    ['Amount', htmlspecialchars(($sub['currency'] ?: 'EGP').' '.$sub['amount']), '#6366f1'],
                    ['Renewal Date', htmlspecialchars($sub['next_payment_date']), '#f59e0b'],
                ]) .
                email_btn('https://socialflow.admepro.com', 'Manage Subscription')
            );
            $subject = '[SocialFlow] Subscription renewing soon — '.($sub['client_name'] ?: $sub['service_name']);
            [$ok, $raw] = send_via_resend($m['email'], $subject, $html);
            log_email($insertLog, $m['email'], $subject, $ok, $raw, $now);
            if ($ok) $counts['subscription_renewal']++;
        }
        $markSubSent->execute([':id' => $sub['id']]);
    }

    echo json_encode(['mode'=>'reminders','sent'=>$counts]);
    exit;
}

if ($mode === 'digest') {
    $team = $pdo->query("SELECT * FROM team_members WHERE status = 'active' AND role NOT IN ('client')")->fetchAll(PDO::FETCH_ASSOC);
    $posts = $pdo->query("SELECT * FROM posts")->fetchAll(PDO::FETCH_ASSOC);
    $section = function($title, $color, $tasks) {
        if (count($tasks) === 0) return '';
        $rows = '';
        foreach ($tasks as $t) {
            $rows .= '<tr style="border-bottom:1px solid #f3f4f6">
              <td style="padding:10px 14px;font-size:13px;color:#111827;font-weight:600">'.htmlspecialchars($t['title'] ?: 'Untitled').'</td>
              <td style="padding:10px 14px;font-size:12px;color:#6b7280;text-align:right">'.htmlspecialchars($t['client_name'] ?: '—').'</td>
            </tr>';
        }
        return '<p style="margin:20px 0 8px;font-size:11px;font-weight:800;color:'.$color.';letter-spacing:0.08em;text-transform:uppercase">'.$title.' ('.count($tasks).')</p>
          <table width="100%" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:12px">'.$rows.'</table>';
    };

    foreach ($team as $m) {
        $prefs = get_prefs($pdo, $m['email']);
        if (!pref_allows($prefs, 'daily_digest')) continue;
        if (!empty($prefs['digest_last_sent']) && substr($prefs['digest_last_sent'], 0, 10) === $today) continue;

        $assigned = array_values(array_filter($posts, fn($p) => $p['assigned_to'] === $m['email']));
        $dueToday = array_values(array_filter($assigned, fn($p) => $p['scheduled_date'] === $today && !in_array($p['stage'], ['published','rejected'])));
        $overdue  = array_values(array_filter($assigned, fn($p) => $p['scheduled_date'] && $p['scheduled_date'] < $today && !in_array($p['stage'], ['published','rejected'])));
        $completed= array_values(array_filter($assigned, fn($p) => $p['scheduled_date'] === $today && in_array($p['stage'], ['published','scheduled'])));
        $pending  = array_values(array_filter($assigned, fn($p) => !in_array($p['stage'], ['published','rejected']) && $p['scheduled_date'] !== $today));

        if (count($dueToday)===0 && count($overdue)===0 && count($completed)===0 && count($pending)===0) continue;

        $html = email_base(
            email_h('Your daily summary') .
            email_p('Hi <strong>'.htmlspecialchars($m['name']).'</strong>, here\'s your task overview for today.') .
            email_card([
                ['Due Today', count($dueToday), count($dueToday)>0?'#f59e0b':'#10b981'],
                ['Overdue', count($overdue), count($overdue)>0?'#ef4444':'#10b981'],
                ['Completed', count($completed), '#10b981'],
                ['Pending', count($pending), '#6b7280'],
            ]) .
            email_divider() .
            $section('Due Today', '#f59e0b', $dueToday) .
            $section('Overdue', '#ef4444', $overdue) .
            $section('Completed', '#10b981', $completed) .
            $section('Pending', '#6b7280', $pending) .
            email_btn('https://socialflow.admepro.com', 'Open SocialFlow')
        );
        [$ok, $raw] = send_via_resend($m['email'], '[SocialFlow] Your daily summary — '.$now->format('d M Y'), $html);
        log_email($insertLog, $m['email'], '[SocialFlow] Your daily summary', $ok, $raw, $now);
        if ($ok) {
            $counts['daily_digest']++;
            $upd = $pdo->prepare("UPDATE notification_prefs SET digest_last_sent = :d WHERE user_email = :e");
            $upd->execute([':d' => $now->format('Y-m-d\TH:i:s\Z'), ':e' => $m['email']]);
            if ($upd->rowCount() === 0) {
                // No notification_prefs row yet for this user — create one so
                // digest_last_sent actually sticks (otherwise it'd resend daily).
                $ins = $pdo->prepare("INSERT INTO notification_prefs (id, user_email, digest_last_sent) VALUES (UUID(), :e, :d)");
                $ins->execute([':e' => $m['email'], ':d' => $now->format('Y-m-d\TH:i:s\Z')]);
            }
        }
    }

    echo json_encode(['mode'=>'digest','sent'=>$counts]);
    exit;
}

echo json_encode(['error'=>'unknown mode']);
