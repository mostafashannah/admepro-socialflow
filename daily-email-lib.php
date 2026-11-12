<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }
/**
 * daily-email-lib.php — builds the Daily Performance Summary email from TODAY's
 * real data (same sources the app uses):
 *   • finished today      → performance_logs completed today (not "sent back" rows)
 *   • sent back today     → performance_logs "returned_*" rows today
 *   • still open today    → tasks in the person's own stage due today
 *   • delayed now         → tasks in their own stage past their due day/time
 *   • hours               → time_entries for today (running timers included)
 *   • tomorrow            → tasks in their stage due tomorrow
 * Admins get a team overview instead of a personal summary.
 * Used by daily-email-cron.php (nightly) and daily-email.php (app preview / test / send all).
 */

const DE_OWN_STAGE = ['graphic_designer' => 'design', 'video_editor' => 'design', 'motion_designer' => 'design', 'content_creator' => 'content_creation'];
const DE_LOGO = 'https://admepro.com/wp-content/uploads/2024/10/adme-p2.png';

function de_load(PDO $pdo, DateTimeZone $tz) {
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
    // Today's window in UTC (DB timestamps are stored in UTC).
    $start = new DateTime($today . ' 00:00:00', $tz); $end = (clone $start)->modify('+1 day');
    $s = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $e = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $team = $pdo->query("SELECT id, name, email, role FROM team_members WHERE status = 'active' AND role NOT IN ('client','accountant','office_boy')")->fetchAll(PDO::FETCH_ASSOC);
    $posts = $pdo->query("SELECT id, title, client_name, stage, assigned_to, content_assigned_to, design_assigned_to, due_date, due_time, is_routine, is_plan_parent FROM posts WHERE stage NOT IN ('published','cancelled','rejected','approved','scheduled') AND (is_plan_parent IS NULL OR is_plan_parent = 0)")->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT user_email, post_title, client_name, stage_from, stage_to, on_time FROM performance_logs WHERE completed_at >= ? AND completed_at < ?");
    $st->execute([$s, $e]); $logs = $st->fetchAll(PDO::FETCH_ASSOC);
    $te = [];
    try {
        $st = $pdo->prepare("SELECT user_email, total_seconds, status, started_at FROM time_entries WHERE LEFT(date, 10) = ?");
        $st->execute([$today]); $te = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $x) {}
    return compact('now', 'today', 'tomorrow', 'team', 'posts', 'logs', 'te');
}

function de_member_stats(array $m, array $D) {
    $em = $m['email'];
    $own = DE_OWN_STAGE[$m['role']] ?? null;
    $mine = array_values(array_filter($D['posts'], function ($p) use ($em, $own) {
        if (!empty($p['is_routine'])) return false;
        if ($own) {
            if ($p['stage'] !== $own) return false;
            $who = $own === 'design' ? ($p['design_assigned_to'] ?: $p['assigned_to']) : ($p['content_assigned_to'] ?: $p['assigned_to']);
            return $who === $em;
        }
        return $p['assigned_to'] === $em && !in_array($p['stage'], ['client_approval'], true);
    }));
    $nowTs = $D['now']->getTimestamp(); $tz = $D['now']->getTimezone();
    $dueTs = function ($p) use ($tz) {
        $d = substr((string)$p['due_date'], 0, 10); if (!$d) return null;
        $t = preg_match('/^\d{1,2}:\d{2}/', (string)$p['due_time']) ? substr($p['due_time'], 0, 5) : '23:59';
        return (new DateTime("$d $t", $tz))->getTimestamp();
    };
    $delayed = array_values(array_filter($mine, fn($p) => ($t = $dueTs($p)) !== null && $t < $nowTs));
    $openToday = array_values(array_filter($mine, fn($p) => substr((string)$p['due_date'], 0, 10) === $D['today'] && !in_array($p, $delayed, true)));
    $tomorrow = array_values(array_filter($mine, fn($p) => substr((string)$p['due_date'], 0, 10) === $D['tomorrow']));
    $done = array_values(array_filter($D['logs'], fn($l) => $l['user_email'] === $em && strpos((string)$l['stage_from'], 'returned_') !== 0));
    $returned = array_values(array_filter($D['logs'], fn($l) => $l['user_email'] === $em && strpos((string)$l['stage_from'], 'returned_') === 0));
    $onTime = count($done) ? round(count(array_filter($done, fn($l) => (int)$l['on_time'] === 1)) / count($done) * 100) : null;
    $secs = 0;
    foreach ($D['te'] as $t) {
        if ($t['user_email'] !== $em) continue;
        $secs += (int)$t['total_seconds'];
        if ($t['status'] === 'active' && $t['started_at']) $secs += max(0, time() - strtotime($t['started_at'] . ' UTC'));
    }
    return compact('done', 'returned', 'openToday', 'delayed', 'tomorrow', 'onTime', 'secs');
}

function de_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function de_hours($secs) { $h = intdiv((int)$secs, 3600); $m = intdiv((int)$secs % 3600, 60); return $h ? "{$h}h " . str_pad($m, 2, '0', STR_PAD_LEFT) . "m" : "{$m}m"; }

function de_shell($title, $intro, $body, $footer) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 14px"><tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb">
  <tr><td style="padding:30px 32px 0"><img src="' . DE_LOGO . '" alt="Admepro" style="height:28px;width:auto"/></td></tr>
  <tr><td style="padding:22px 32px 8px">
    <h2 style="margin:0 0 6px;font-size:20px;font-weight:800;color:#111827">' . $title . '</h2>
    <p style="margin:0;font-size:14px;line-height:1.6;color:#4b5563">' . $intro . '</p>
  </td></tr>' . $body . '
  <tr><td style="padding:8px 32px 30px">
    <table width="100%" style="border-top:1px solid #e5e7eb;margin-top:16px;padding-top:18px"><tr><td>
      <p style="margin:0 0 4px;font-size:14px;font-weight:700;color:#111827">Admepro Management</p>
      <p style="margin:0;font-size:13px;color:#6b7280">' . $footer . '</p>
      <p style="margin:6px 0 0;font-size:11px;color:#9ca3af">Automated daily summary from SocialFlow · numbers are for today only</p>
    </td></tr></table>
  </td></tr>
</table></td></tr></table></body></html>';
}

function de_stats_row(array $cells) {
    $w = floor(100 / count($cells));
    $h = '<tr><td style="padding:16px 26px 4px"><table width="100%" cellpadding="0" cellspacing="0"><tr>';
    foreach ($cells as [$label, $value, $color]) {
        $h .= '<td width="' . $w . '%" style="padding:5px"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px"><tr><td style="padding:14px 6px;text-align:center">
          <div style="font-size:24px;font-weight:800;color:' . $color . ';line-height:1">' . $value . '</div>
          <div style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-top:6px;font-weight:700">' . $label . '</div>
        </td></tr></table></td>';
    }
    return $h . '</tr></table></td></tr>';
}

function de_list($title, array $items, $color, $empty = null) {
    if (!$items && $empty === null) return '';
    $h = '<tr><td style="padding:16px 32px 0"><p style="margin:0 0 8px;font-size:12px;font-weight:800;color:#111827;text-transform:uppercase;letter-spacing:.06em">' . $title . '</p>';
    if (!$items) return $h . '<p style="margin:0;font-size:13px;color:#6b7280">' . $empty . '</p></td></tr>';
    foreach (array_slice($items, 0, 6) as [$main, $sub]) {
        $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:6px;border-left:3px solid ' . $color . ';background:#f9fafb;border-radius:8px"><tr><td style="padding:9px 12px">
          <span style="font-size:13px;font-weight:700;color:#111827">' . de_h($main) . '</span>' . ($sub ? '<span style="font-size:12px;color:#6b7280"> · ' . de_h($sub) . '</span>' : '') . '</td></tr></table>';
    }
    if (count($items) > 6) $h .= '<p style="margin:2px 0 0;font-size:12px;color:#6b7280">+' . (count($items) - 6) . ' more</p>';
    return $h . '</td></tr>';
}

function de_due_label($p) { $t = preg_match('/^\d{1,2}:\d{2}/', (string)$p['due_time']) ? date('g:i A', strtotime(substr($p['due_time'], 0, 5))) : ''; return trim(($p['client_name'] ?: '') . ($t ? ' · due ' . $t : '')); }

function de_member_html(array $es, array $m, array $D, $motiv) {
    $S = de_member_stats($m, $D);
    $first = de_h(explode(' ', trim($m['name']))[0] ?: 'there');
    $date = $D['now']->format('l, j F');
    $nDone = count($S['done']); $nDel = count($S['delayed']);
    $intro = $nDone
        ? "Here's how your day went — you finished <b>$nDone</b> task" . ($nDone > 1 ? 's' : '') . " today" . ($nDel ? ", and <b>$nDel</b> still need" . ($nDel > 1 ? '' : 's') . " attention." : ". Nice work! 🎉")
        : ($nDel ? "Nothing was finished today yet, and <b>$nDel</b> task" . ($nDel > 1 ? 's are' : ' is') . " past due — let's clear them first tomorrow." : "Here's your summary for $date.");
    $body = '';
    if (!empty($es['include_metrics'])) {
        $body .= de_stats_row([
            ['Done today', $nDone, '#10b981'],
            ['Delayed', $nDel, $nDel ? '#ef4444' : '#10b981'],
            ['Sent back', count($S['returned']), count($S['returned']) ? '#f97316' : '#10b981'],
            ['On time', $S['onTime'] === null ? '—' : $S['onTime'] . '%', $S['onTime'] === null ? '#9ca3af' : ($S['onTime'] >= 80 ? '#10b981' : '#f59e0b')],
        ]);
    }
    if (!empty($es['include_working_hours'])) {
        $body .= '<tr><td style="padding:8px 32px 0"><table width="100%" cellpadding="0" cellspacing="0" style="background:#111827;border-radius:12px"><tr><td style="padding:14px 18px">
          <span style="font-size:12px;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Time tracked today</span><br/>
          <span style="font-size:24px;font-weight:800;color:#ffffff">' . de_hours($S['secs']) . '</span>' . ($nDone ? '<span style="font-size:13px;color:#9ca3af"> · about ' . de_hours($S['secs'] / max(1, $nDone)) . ' per finished task</span>' : '') . '
        </td></tr></table></td></tr>';
    }
    if (!empty($es['include_completed_tasks'])) $body .= de_list('✓ Finished today', array_map(fn($l) => [$l['post_title'], ($l['client_name'] ?: '') . ((int)$l['on_time'] === 1 ? ' · on time' : ' · late')], $S['done']), '#10b981', $S['done'] ? null : 'Nothing finished today.');
    if (!empty($es['include_overdue_tasks'])) $body .= de_list('⏰ Delayed', array_map(fn($p) => [$p['title'], trim(($p['client_name'] ?: '') . ' · was due ' . date('j M', strtotime($p['due_date'])))], $S['delayed']), '#ef4444');
    if (!empty($es['include_pending_tasks'])) {
        $body .= de_list('Still open today', array_map(fn($p) => [$p['title'], de_due_label($p)], $S['openToday']), '#f59e0b');
        $body .= de_list('📅 Tomorrow', array_map(fn($p) => [$p['title'], de_due_label($p)], $S['tomorrow']), '#6366f1');
    }
    if (!empty($es['include_motivation']) && $motiv) {
        $body .= '<tr><td style="padding:18px 32px 0"><p style="margin:0;padding:14px 16px;background:#fef2f2;border-radius:10px;font-size:14px;line-height:1.6;color:#7f1d1d;font-style:italic;text-align:center">“' . de_h($motiv) . '”</p></td></tr>';
    }
    return de_shell("Hi $first, here's your day", $intro, $body, de_h($es['custom_footer'] ?? '') ?: 'Keep up the great work!');
}

/** Team-today block (stats + per-person table) as a standalone snippet for other emails. */
function de_team_section(array $D) {
    $inner = de_admin_rows($D);
    return '<table width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 6px"><tr><td style="font-size:16px;font-weight:800;color:#111827;padding:0 0 4px">👥 Team today</td></tr></table>'
        . '<table width="100%" cellpadding="0" cellspacing="0">' . $inner['stats'] . $inner['table'] . '</table>';
}

function de_admin_rows(array $D) {
    $rows = []; $T = ['done' => 0, 'del' => 0, 'ret' => 0, 'secs' => 0];
    foreach ($D['team'] as $p) {
        if ($p['role'] === 'admin') continue;
        $S = de_member_stats($p, $D);
        $T['done'] += count($S['done']); $T['del'] += count($S['delayed']); $T['ret'] += count($S['returned']); $T['secs'] += $S['secs'];
        $rows[] = [$p['name'], count($S['done']), count($S['delayed']), count($S['returned']), $S['secs']];
    }
    usort($rows, fn($a, $b) => $b[2] <=> $a[2] ?: $b[1] <=> $a[1]);
    $stats = de_stats_row([['Done today', $T['done'], '#10b981'], ['Delayed', $T['del'], $T['del'] ? '#ef4444' : '#10b981'], ['Sent back', $T['ret'], $T['ret'] ? '#f97316' : '#10b981'], ['Tracked', de_hours($T['secs']), '#111827']]);
    $tbl = '<tr><td style="padding:12px 0 0"><table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;font-size:13px">
      <tr style="background:#f9fafb;color:#6b7280;font-size:11px;text-transform:uppercase"><td style="padding:8px 10px">Person</td><td style="padding:8px 6px;text-align:center">Done</td><td style="padding:8px 6px;text-align:center">Delayed</td><td style="padding:8px 6px;text-align:center">Back</td><td style="padding:8px 10px;text-align:right">Time</td></tr>';
    foreach ($rows as [$n, $d, $l, $r, $sec]) {
        $tbl .= '<tr><td style="padding:8px 10px;font-weight:700;color:#111827;border-top:1px solid #f3f4f6">' . de_h($n) . '</td>'
            . '<td style="padding:8px 6px;text-align:center;color:#10b981;font-weight:700;border-top:1px solid #f3f4f6">' . $d . '</td>'
            . '<td style="padding:8px 6px;text-align:center;font-weight:700;color:' . ($l ? '#ef4444' : '#9ca3af') . ';border-top:1px solid #f3f4f6">' . $l . '</td>'
            . '<td style="padding:8px 6px;text-align:center;color:' . ($r ? '#f97316' : '#9ca3af') . ';border-top:1px solid #f3f4f6">' . $r . '</td>'
            . '<td style="padding:8px 10px;text-align:right;color:#4b5563;border-top:1px solid #f3f4f6">' . de_hours($sec) . '</td></tr>';
    }
    $tbl .= '</table></td></tr>';
    return ['stats' => $stats, 'table' => $tbl, 'T' => $T];
}

function de_admin_html(array $es, array $m, array $D) {
    $first = de_h(explode(' ', trim($m['name']))[0] ?: 'there');
    $rows = []; $T = ['done' => 0, 'del' => 0, 'ret' => 0, 'secs' => 0];
    foreach ($D['team'] as $p) {
        if ($p['role'] === 'admin') continue;
        $S = de_member_stats($p, $D);
        $T['done'] += count($S['done']); $T['del'] += count($S['delayed']); $T['ret'] += count($S['returned']); $T['secs'] += $S['secs'];
        $rows[] = [$p['name'], count($S['done']), count($S['delayed']), count($S['returned']), $S['secs']];
    }
    usort($rows, fn($a, $b) => $b[2] <=> $a[2] ?: $b[1] <=> $a[1]);
    $body = de_stats_row([['Done today', $T['done'], '#10b981'], ['Delayed', $T['del'], $T['del'] ? '#ef4444' : '#10b981'], ['Sent back', $T['ret'], $T['ret'] ? '#f97316' : '#10b981'], ['Tracked', de_hours($T['secs']), '#111827']]);
    $tbl = '<tr><td style="padding:16px 32px 0"><p style="margin:0 0 8px;font-size:12px;font-weight:800;color:#111827;text-transform:uppercase;letter-spacing:.06em">Team today</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;font-size:13px">
      <tr style="background:#f9fafb;color:#6b7280;font-size:11px;text-transform:uppercase"><td style="padding:8px 10px">Person</td><td style="padding:8px 6px;text-align:center">Done</td><td style="padding:8px 6px;text-align:center">Delayed</td><td style="padding:8px 6px;text-align:center">Back</td><td style="padding:8px 10px;text-align:right">Time</td></tr>';
    foreach ($rows as [$n, $d, $l, $r, $s]) {
        $tbl .= '<tr style="border-top:1px solid #e5e7eb"><td style="padding:8px 10px;font-weight:700;color:#111827;border-top:1px solid #f3f4f6">' . de_h($n) . '</td>'
            . '<td style="padding:8px 6px;text-align:center;color:#10b981;font-weight:700;border-top:1px solid #f3f4f6">' . $d . '</td>'
            . '<td style="padding:8px 6px;text-align:center;font-weight:700;color:' . ($l ? '#ef4444' : '#9ca3af') . ';border-top:1px solid #f3f4f6">' . $l . '</td>'
            . '<td style="padding:8px 6px;text-align:center;color:' . ($r ? '#f97316' : '#9ca3af') . ';border-top:1px solid #f3f4f6">' . $r . '</td>'
            . '<td style="padding:8px 10px;text-align:right;color:#4b5563;border-top:1px solid #f3f4f6">' . de_hours($s) . '</td></tr>';
    }
    $body .= $tbl . '</table></td></tr>';
    return de_shell("Hi $first, here's the team's day", "Today's summary across the team — " . $T['done'] . " tasks finished, " . $T['del'] . " delayed right now.", $body, de_h($es['custom_footer'] ?? ''));
}

function de_html_for(array $es, array $member, array $D, $motiv) {
    return $member['role'] === 'admin' ? de_admin_html($es, $member, $D) : de_member_html($es, $member, $D, $motiv);
}

function de_subject($es, DateTimeZone $tz) {
    return str_ireplace('{date}', (new DateTime('now', $tz))->format('d M Y'), $es['subject'] ?: 'Your Daily Performance Report');
}

function de_motivation() {
    $m = [
        "Every completed task brings you closer to excellence. Keep going!",
        "Your dedication today builds the success of tomorrow. Well done!",
        "Consistency is the key to extraordinary results.",
        "Small progress every day adds up to big results.",
        "The team is proud of your efforts. Keep it up!",
    ];
    return $m[array_rand($m)];
}

function de_send($to, $subject, $html, $fromName) {
    $ch = curl_init("https://api.resend.com/emails");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => json_encode(["from" => "$fromName <noreply@admepro.com>", "to" => [$to], "subject" => $subject, "html" => $html]),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . RESEND_API_KEY, "Content-Type: application/json"],
    ]);
    $res = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status >= 200 && $status < 300, $res];
}
