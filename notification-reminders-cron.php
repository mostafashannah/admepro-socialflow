<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
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
 *   php notification-reminders-cron.php digest       — end-of-day (22:00) brief for ADMINS only
 *   php notification-reminders-cron.php digest_team  — the original task digest for everyone else, sent at 11:00 (run every 10 min)
 *
 * Crontab:
 *   (star)/15 (star) (star) (star) (star) /usr/bin/php /var/www/socialflow/notification-reminders-cron.php reminders >> /var/www/socialflow/notification-reminders-cron.log 2>&1
 *   (star)/10 (star) (star) (star) (star) /usr/bin/php /var/www/socialflow/notification-reminders-cron.php digest >> /var/www/socialflow/notification-reminders-cron.log 2>&1
 *     (every 10 min — the script itself waits for 22:00 Cairo time and sends once a day)
 * (5-field cron wildcard syntax — written out here so this docblock comment doesn't break PHP parsing)
 */

// CLI-only — this script performs real writes (emails, DB records) and has
// no authentication of its own, so it must never be reachable over plain
// HTTP (this file sits in the public web root alongside the app).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/pro-lib.php'; // sendWhatsAppReply() — the brief also goes out on WhatsApp

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
    $recipients = $pdo->query("SELECT * FROM team_members WHERE status = 'active' AND role IN ('admin','accountant','account_manager','account_director','account_executive')")->fetchAll(PDO::FETCH_ASSOC);
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

// Turns the brief's HTML lines into a WhatsApp-style text (same content, *bold* headings).
function brief_to_whatsapp($html) {
    $t = preg_replace('/<br\s*\/?>/i', "\n   ", $html);
    $t = preg_replace_callback('/<p style="margin:18px 0 6px;[^>]*>(.*?)<\/p>/s', fn($m) => "\n*".trim(strip_tags($m[1]))."*\n", $t);
    $t = preg_replace('/<strong[^>]*>(.*?)<\/strong>/s', '*$1*', $t);
    $t = preg_replace('/<p[^>]*>(.*?)<\/p>/s', "$1\n", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
    $t = preg_replace("/\n{3,}/", "\n\n", trim($t));
    return $t;
}

if ($mode === 'digest') {
    // End-of-day brief: goes out at 22:00 Cairo time. Run this every 10 minutes —
    // it only sends once the time has passed, and at most once per person per day.
    $DIGEST_SEND_TIME = '22:00';
    // Test run: `php notification-reminders-cron.php digest test you@email.com` sends ONE brief
    // to that person right now (ignores the 10 PM gate and the once-a-day guard).
    $testEmail = (($argv[2] ?? '') === 'test') ? strtolower(trim($argv[3] ?? '')) : '';
    // ── Daily Digest = a SHORT brief (main points only), built from the sections
    // each person has switched on in Account > Notifications (digest_sections).
    // Finance is for admin/accountant, Recruitment for admin/hr/account_director.
    $team = $pdo->query("SELECT * FROM team_members WHERE status = 'active' AND role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
    $posts = $pdo->query("SELECT id, title, client_name, assigned_to, stage, scheduled_date FROM posts")->fetchAll(PDO::FETCH_ASSOC);
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    $monthStart = $now->format('Y-m-01');
    $money = function($n) { return 'EGP ' . number_format((float)$n, 0); };
    $one = function($sql, $params = []) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($params); return (float)$st->fetchColumn(); } catch (Throwable $e) { return 0.0; } };
    $rowsOf = function($sql, $params = []) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; } };

    // Finance numbers are the same for everyone who sees them — compute lazily, once.
    $financeCache = null;
    $finance = function() use (&$financeCache, $one, $yesterday, $monthStart, $today) {
        if ($financeCache !== null) return $financeCache;
        $inRange = function($from, $to) use ($one) {
            $a = $one("SELECT COALESCE(SUM(amount),0) FROM payments WHERE LEFT(payment_date,10) BETWEEN :f AND :t", [':f'=>$from, ':t'=>$to]);
            $b = $one("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE LEFT(payment_date,10) BETWEEN :f AND :t", [':f'=>$from, ':t'=>$to]);
            $c = $one("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE type = 'in' AND outstanding_kind IS NULL AND LEFT(date,10) BETWEEN :f AND :t", [':f'=>$from, ':t'=>$to]);
            return $a + $b + $c;
        };
        $outRange = function($from, $to) use ($one) {
            $a = $one("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE (type IS NULL OR type = 'out') AND outstanding_kind IS NULL AND LEFT(date,10) BETWEEN :f AND :t", [':f'=>$from, ':t'=>$to]);
            $b = $one("SELECT COALESCE(SUM(op.amount),0) FROM outstanding_payments op JOIN expenses e ON e.id = op.expense_id WHERE (e.type IS NULL OR e.type = 'out') AND LEFT(op.date,10) BETWEEN :f AND :t", [':f'=>$from, ':t'=>$to]);
            return $a + $b;
        };
        $owed = $one("SELECT COALESCE(SUM(COALESCE(e.outstanding_total_payable, e.amount) - COALESCE(p.paid,0)),0) FROM expenses e
            LEFT JOIN (SELECT expense_id, SUM(amount) paid FROM outstanding_payments GROUP BY expense_id) p ON p.expense_id = e.id
            WHERE e.outstanding_kind IS NOT NULL AND (e.outstanding_status IS NULL OR e.outstanding_status <> 'settled') AND (e.type IS NULL OR e.type = 'out')");
        $inv = $one("SELECT COUNT(*) FROM invoices WHERE status NOT IN ('paid','cancelled','draft') AND due_date IS NOT NULL AND due_date <> '' AND LEFT(due_date,10) < :t", [':t'=>$today]);
        $invAmt = $one("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE status NOT IN ('paid','cancelled','draft') AND due_date IS NOT NULL AND due_date <> '' AND LEFT(due_date,10) < :t", [':t'=>$today]);
        return $financeCache = [
            'y_in' => $inRange($today, $today), 'y_out' => $outRange($today, $today),
            'm_in' => $inRange($monthStart, $today),    'm_out' => $outRange($monthStart, $today),
            'owed' => $owed, 'inv_overdue' => (int)$inv, 'inv_overdue_amt' => $invAmt,
        ];
    };
    $recruitCache = null;
    $recruit = function() use (&$recruitCache, $one, $rowsOf) {
        if ($recruitCache !== null) return $recruitCache;
        return $recruitCache = [
            'new' => $rowsOf("SELECT candidate_name, job_title, ai_score FROM job_applications WHERE created_at >= (NOW() - INTERVAL 24 HOUR) ORDER BY created_at DESC LIMIT 50"),
            'awaiting' => (int)$one("SELECT COUNT(*) FROM job_applications WHERE status = 'new'"),
            'offers' => (int)$one("SELECT COUNT(*) FROM job_applications WHERE status = 'offer' AND (offer_candidate_response IS NULL OR offer_candidate_response = '')"),
        ];
    };

    // AM check-ins (Mai's morning + end-of-day WhatsApp check-ins with account managers):
    // today's scores, progress vs the last 7 days, and what keeps getting missed.
    $amCache = null;
    $amData = function() use (&$amCache, $rowsOf, $today) {
        if ($amCache !== null) return $amCache;
        $since = (new DateTime($today))->modify('-7 day')->format('Y-m-d');
        $rows = $rowsOf("SELECT account_manager_id, account_manager_name AS name, report_type, report_date, status, score, max_score, checklist
            FROM mai_report_sessions WHERE report_date >= :s AND report_type IN ('morning','eod')", [':s'=>$since]);
        $pct = fn($r) => (!empty($r['max_score']) && $r['score'] !== null) ? ((int)$r['score'] / (int)$r['max_score'] * 100) : null;
        $todayRows = []; $histByAm = []; $missed = [];
        foreach ($rows as $r) {
            $d = substr($r['report_date'], 0, 10);
            $cl = json_decode((string)$r['checklist'], true) ?: [];
            if ($d === $today) {
                $todayRows[$r['name']][$r['report_type']] = $r;
            } elseif ($r['status'] === 'completed' && ($pc = $pct($r)) !== null) {
                $histByAm[$r['name']][] = $pc;
            }
            if ($r['status'] === 'completed') {
                foreach ($cl as $k => $item) {
                    if (empty($item['done'])) $missed[$item['label'] ?? $k] = ($missed[$item['label'] ?? $k] ?? 0) + 1;
                }
            }
        }
        arsort($missed);
        return $amCache = ['today'=>$todayRows, 'hist'=>$histByAm, 'missed'=>$missed, 'pct'=>$pct];
    };

    $line = fn($text) => '<p style="margin:0 0 5px;font-size:13px;line-height:1.55;color:#374151">'.$text.'</p>';
    $head = fn($title, $color) => '<p style="margin:18px 0 6px;font-size:11px;font-weight:800;color:'.$color.';letter-spacing:0.08em;text-transform:uppercase">'.$title.'</p>';
    $b = fn($t) => '<strong style="color:#111827">'.$t.'</strong>';
    $teamOverdue = count(array_filter($posts, fn($p) => $p['scheduled_date'] && $p['scheduled_date'] < $today && !in_array($p['stage'], ['published','rejected','scheduled','approved'])));

    foreach ($team as $m) {
        if ($testEmail && strtolower($m['email']) !== $testEmail) continue;
        $prefs = get_prefs($pdo, $m['email']);
        if (!$testEmail) {
            if (!pref_allows($prefs, 'daily_digest')) continue;
            if (!empty($prefs['digest_last_sent']) && substr($prefs['digest_last_sent'], 0, 10) === $today) continue;
            $mySendAt = preg_match('/^\d{2}:\d{2}$/', (string)($prefs['digest_time'] ?? '')) ? $prefs['digest_time'] : $DIGEST_SEND_TIME;
            if ($now->format('H:i') < $mySendAt) continue; // each person's own send time
        }

        $role = $m['role'];
        $canFinance = in_array($role, ['admin','accountant'], true);
        $canRecruit = in_array($role, ['admin','hr','account_director'], true);
        $canAm = ($role === 'admin');
        $want = json_decode((string)($prefs['digest_sections'] ?? ''), true);
        if (!is_array($want)) $want = [];
        $on = fn($k) => !array_key_exists($k, $want) || !empty($want[$k]); // default: everything the person may see

        $body = '';

        if ($on('tasks')) {
            $assigned = array_values(array_filter($posts, fn($p) => $p['assigned_to'] === $m['email']));
            $dueToday = array_filter($assigned, fn($p) => $p['scheduled_date'] === $today && !in_array($p['stage'], ['published','rejected']));
            $overdue  = array_values(array_filter($assigned, fn($p) => $p['scheduled_date'] && $p['scheduled_date'] < $today && !in_array($p['stage'], ['published','rejected','scheduled','approved'])));
            $pending  = array_filter($assigned, fn($p) => !in_array($p['stage'], ['published','rejected','scheduled','approved']));
            if (count($dueToday) || count($overdue) || count($pending)) {
                $body .= $head('Tasks', '#3b82f6');
                $doneToday = (int)$one("SELECT COUNT(*) FROM task_logs WHERE assignee_email = :e AND LEFT(completed_at,10) = :t", [':e'=>$m['email'], ':t'=>$today]);
                $body .= $line(($doneToday ? $b($doneToday).' finished today · ' : '').$b(count($dueToday)).' due today · '.$b(count($overdue)).' overdue · '.$b(count($pending)).' open in total');
                foreach (array_slice($overdue, 0, 3) as $t) $body .= $line('• '.htmlspecialchars($t['title'] ?: 'Untitled').' <span style="color:#9ca3af">— '.htmlspecialchars($t['client_name'] ?: '').'</span>');
                if (count($overdue) > 3) $body .= $line('<span style="color:#9ca3af">…and '.(count($overdue)-3).' more overdue</span>');
            }
            if (in_array($role, ['admin','account_manager','account_director','account_executive'], true) && $teamOverdue > 0) {
                if ($body === '' || strpos($body, 'Tasks') === false) $body .= $head('Tasks', '#3b82f6');
                $body .= $line('Whole team: '.$b($teamOverdue).' overdue task'.($teamOverdue==1?'':'s'));
            }
        }

        if ($on('finance') && $canFinance) {
            $f = $finance();
            $body .= $head('Finance', '#10b981');
            $body .= $line('Today: in '.$b($money($f['y_in'])).' · out '.$b($money($f['y_out'])));
            $net = $f['m_in'] - $f['m_out'];
            $body .= $line('This month so far: in '.$b($money($f['m_in'])).' · out '.$b($money($f['m_out'])).' · net <strong style="color:'.($net>=0?'#059669':'#dc2626').'">'.($net<0?'−':'').$money(abs($net)).'</strong>');
            if ($f['owed'] > 0) $body .= $line('Still owed (outstanding): '.$b($money($f['owed'])));
            if ($f['inv_overdue'] > 0) $body .= $line('<span style="color:#dc2626">'.$f['inv_overdue'].' overdue invoice'.($f['inv_overdue']==1?'':'s').' — '.$money($f['inv_overdue_amt']).'</span>');
        }

        if ($on('recruitment') && $canRecruit) {
            $r = $recruit();
            if (count($r['new']) || $r['awaiting'] || $r['offers']) {
                $body .= $head('Recruitment', '#8b5cf6');
                $body .= $line($b(count($r['new'])).' new application'.(count($r['new'])==1?'':'s').' · '.$b($r['awaiting']).' awaiting review'.($r['offers'] ? ' · '.$b($r['offers']).' offer'.($r['offers']==1?'':'s').' waiting for an answer' : ''));
                foreach (array_slice($r['new'], 0, 3) as $a) $body .= $line('• '.htmlspecialchars($a['candidate_name'] ?: 'Unknown').' <span style="color:#9ca3af">— '.htmlspecialchars($a['job_title'] ?: 'role').($a['ai_score'] !== null ? ' ('.(int)$a['ai_score'].'/100)' : '').'</span>');
            }
        }

        if ($on('am_checkins') && $canAm) {
            $am = $amData();
            if ($am['today'] || $am['hist']) {
                $body .= $head('Account manager check-ins', '#f59e0b');
                $todayPcts = [];
                foreach ($am['today'] as $name => $types) {
                    $bits = [];
                    foreach (['morning'=>'Morning','eod'=>'End of day'] as $t => $lab) {
                        $r = $types[$t] ?? null;
                        if (!$r) { $bits[] = $lab.': <span style="color:#9ca3af">not started</span>'; continue; }
                        if ($r['status'] !== 'completed') { $bits[] = $lab.': <span style="color:#dc2626">no reply</span>'; continue; }
                        $pc = $am['pct']($r);
                        if ($pc !== null) $todayPcts[] = $pc;
                        $bits[] = $lab.': '.$b((int)$r['score'].'/'.(int)$r['max_score']);
                    }
                    // vs this person's own 7-day average
                    $trend = '';
                    $hist = $am['hist'][$name] ?? [];
                    $mine = array_filter(array_map(fn($t) => (($t['status'] ?? '') === 'completed') ? $am['pct']($t) : null, array_values($types)), fn($x) => $x !== null);
                    if ($hist && $mine) {
                        $diff = round(array_sum($mine)/count($mine) - array_sum($hist)/count($hist));
                        $trend = $diff >= 5 ? ' <span style="color:#059669">▲ +'.$diff.'%</span>' : ($diff <= -5 ? ' <span style="color:#dc2626">▼ '.$diff.'%</span>' : ' <span style="color:#9ca3af">steady</span>');
                    }
                    $body .= $line('• '.$b(htmlspecialchars($name)).' — '.implode(' · ', $bits).$trend);
                }
                $allHist = array_merge(...(array_values($am['hist']) ?: [[]]));
                if ($todayPcts && $allHist) {
                    $t = round(array_sum($todayPcts)/count($todayPcts)); $h = round(array_sum($allHist)/count($allHist));
                    $body .= $line('Team today: '.$b($t.'%').' vs '.$h.'% over the last 7 days '.($t>=$h ? '<span style="color:#059669">▲</span>' : '<span style="color:#dc2626">▼</span>'));
                } elseif ($todayPcts) {
                    $body .= $line('Team today: '.$b(round(array_sum($todayPcts)/count($todayPcts)).'%'));
                }
                if ($am['missed']) {
                    $body .= $line('<span style="color:#b45309">To improve — most missed this week:</span>');
                    foreach (array_slice($am['missed'], 0, 3, true) as $label => $n) $body .= $line('• '.htmlspecialchars($label).' <span style="color:#9ca3af">(missed '.$n.'×)</span>');
                }
            }
        }

        if ($on('meetings') && $canAm) {
            // Meetings that actually happened today = contact reports logged for today (meeting date, or
            // the day it was logged if no date was set). Two-line brief taken from each report.
            $mt = $rowsOf("SELECT client_name, created_by_name, meeting_type, summary, key_points FROM contact_reports
                WHERE (meeting_date = :d OR (meeting_date IS NULL AND DATE(created_at) = :d)) ORDER BY created_at DESC", [':d'=>$today]);
            if (!$mt) $mt = $rowsOf("SELECT client_name, created_by_name, '' AS meeting_type, summary, key_points FROM contact_reports WHERE DATE(created_at) = :d ORDER BY created_at DESC", [':d'=>$today]);
            $body .= $head('Meetings', '#ec4899');
            if (!$mt) {
                $body .= $line('<span style="color:#9ca3af">No meetings were logged today.</span>');
            } else {
                $body .= $line($b(count($mt)).' meeting'.(count($mt)==1?'':'s').' done today');
                foreach (array_slice($mt, 0, 6) as $r) {
                    $txt = trim(preg_replace('/\s+/', ' ', strip_tags((string)($r['summary'] ?: $r['key_points']))));
                    if (mb_strlen($txt) > 230) { $cut = mb_substr($txt, 0, 230); $sp = mb_strrpos($cut, ' '); $txt = ($sp ? mb_substr($cut, 0, $sp) : $cut).'…'; }
                    $body .= $line('• '.$b(htmlspecialchars($r['client_name'] ?: 'Meeting')).($r['created_by_name'] ? ' <span style="color:#9ca3af">('.htmlspecialchars($r['created_by_name']).')</span>' : '').($txt !== '' ? '<br><span style="color:#6b7280">'.htmlspecialchars($txt).'</span>' : ''));
                }
                if (count($mt) > 6) $body .= $line('<span style="color:#9ca3af">…and '.(count($mt)-6).' more</span>');
            }
        }

        if ($on('director') && $canAm) {
            // The Account Director's single 7:30 PM check-in with Mai: what was noted, what Mai did about it.
            $ds = $rowsOf("SELECT account_manager_name AS name, status, score, max_score, checklist, summary FROM mai_report_sessions WHERE report_type = 'director' AND report_date = :d", [':d'=>$today]);
            $dirs = $rowsOf("SELECT name FROM team_members WHERE role = 'account_director' AND status = 'active' AND mai_checkins_enabled = 1");
            if ($ds || $dirs) {
                $body .= $head('Account director report', '#0ea5e9');
                if (!$ds) $body .= $line('<span style="color:#9ca3af">No check-in was started today.</span>');
                foreach ($ds as $s) {
                    $cl = json_decode((string)$s['checklist'], true) ?: [];
                    $done = count(array_filter($cl, fn($v) => !empty($v['done']))); $tot = count($cl);
                    $statusTxt = $s['status'] === 'completed' ? 'replied — '.$done.'/'.$tot.' topics covered' : ($s['status'] === 'missed' ? '<span style="color:#dc2626">did not finish</span>' : 'in progress');
                    $body .= $line($b(htmlspecialchars($s['name'])).' — '.$statusTxt);
                    $sum = json_decode((string)$s['summary'], true) ?: [];
                    foreach (($sum['notes'] ?? []) as $n) {
                        $dot = ($n['priority'] ?? '') === 'high' ? '<span style="color:#dc2626">●</span> ' : '• ';
                        $body .= $line($dot.$b(htmlspecialchars($n['title'])).(!empty($n['detail']) ? ' <span style="color:#6b7280">— '.htmlspecialchars($n['detail']).'</span>' : ''));
                    }
                    if (!empty($sum['facts']) || !empty($sum['leads'])) {
                        $body .= $line('<span style="color:#6b7280">Mai saved '.(int)($sum['facts'] ?? 0).' client fact(s) and logged '.(int)($sum['leads'] ?? 0).' lead update(s).</span>');
                    }
                    $open = array_filter($cl, fn($v) => empty($v['done']));
                    if ($s['status'] !== 'in_progress' && $open) $body .= $line('<span style="color:#b45309">Not covered:</span> '.htmlspecialchars(implode('; ', array_map(fn($v) => $v['label'], $open))));
                }
            }
        }

        if ($body === '') continue; // nothing worth sending

        $sendEmail = !array_key_exists('email', $want) || !empty($want['email']);
        $sendWa = (!array_key_exists('whatsapp', $want) || !empty($want['whatsapp'])) && !empty($m['whatsapp_number']);
        if (!$sendEmail && !$sendWa) continue;
        $first = htmlspecialchars(explode(' ', trim($m['name']))[0] ?: 'there');
        $html = email_base(
            email_h('End-of-day brief') .
            email_p('Good evening <strong>'.$first.'</strong> — the main points from today, '.$now->format('D j M').'.') .
            $body .
            email_btn('https://socialflow.admepro.com', 'Open SocialFlow') .
            '<p style="margin:0;font-size:11px;color:#9ca3af;text-align:center">Choose what appears here in Account → Notifications → Daily Digest.</p>'
        );
        $ok = false; $raw = '';
        if ($sendEmail) {
            [$ok, $raw] = send_via_resend($m['email'], '[SocialFlow] End-of-day brief — '.$now->format('d M Y'), $html);
            log_email($insertLog, $m['email'], '[SocialFlow] Daily brief', $ok, $raw, $now);
        }
        if ($sendWa) {
            $waText = "📋 *End-of-day brief — ".$now->format('D j M')."*\nGood evening ".strip_tags($first).".\n".brief_to_whatsapp($body)."\n\nFull details: socialflow.admepro.com";
            if (mb_strlen($waText) > 3800) $waText = mb_substr($waText, 0, 3790).'…';
            try { $waOk = sendWhatsAppReply($m['whatsapp_number'], $waText); if ($waOk) $ok = true; } catch (Throwable $e) { error_log('[digest] whatsapp: '.$e->getMessage()); }
        }
        if ($testEmail) { echo json_encode(['test'=>$m['email'], 'sent'=>$ok, 'detail'=>$ok ? 'ok' : $raw]); exit; }
        if ($ok) {
            $counts['daily_digest']++;
            $upd = $pdo->prepare("UPDATE notification_prefs SET digest_last_sent = :d WHERE user_email = :e");
            $upd->execute([':d' => $now->format('Y-m-d\TH:i:s\Z'), ':e' => $m['email']]);
            if ($upd->rowCount() === 0) {
                $ins = $pdo->prepare("INSERT INTO notification_prefs (id, user_email, digest_last_sent) VALUES (UUID(), :e, :d)");
                $ins->execute([':e' => $m['email'], ':d' => $now->format('Y-m-d\TH:i:s\Z')]);
            }
        }
    }

    echo json_encode(['mode'=>'digest','sent'=>$counts]);
    exit;
}

if ($mode === 'digest_team') {
    // Goes out at 11:00 Cairo time (run every 10 minutes — it waits for the time and
    // each person's digest_last_sent keeps it to once a day).
    $TEAM_DIGEST_TIME = '11:00';
    // Everyone EXCEPT admins keeps the original morning task digest (unchanged). Admins get the
    // end-of-day brief instead (mode 'digest').
    $team = $pdo->query("SELECT * FROM team_members WHERE status = 'active' AND role NOT IN ('client','admin')")->fetchAll(PDO::FETCH_ASSOC);
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
        $mySendAt = preg_match('/^\d{2}:\d{2}$/', (string)($prefs['digest_time'] ?? '')) ? $prefs['digest_time'] : $TEAM_DIGEST_TIME;
        if ($now->format('H:i') < $mySendAt) continue; // each person's own send time

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
