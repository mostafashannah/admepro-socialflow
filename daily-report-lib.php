<?php
// ================================================================
// WhatsApp Daily Report — settings + report builder.
// Settings live in daily_report_settings (edited in Settings > Daily Report).
// Used by daily-report-cron.php (scheduled send) and daily-report-send.php
// (preview / send-now from the app).
// ================================================================

function drDefaults(): array {
    return [
        'enabled' => 0,
        'send_time' => '19:00',
        'days' => [7, 1, 2, 3, 4], // ISO weekdays: Sun..Thu (Fri/Sat weekend)
        'sections' => ['progress' => true, 'accounts_hint' => true, 'tasks_done' => true, 'tasks_open' => true, 'posting' => true, 'insights' => true, 'applications' => true],
        'client_ids' => [],   // empty = every account with Facebook/Instagram insights
        'recipients' => [],   // team-member emails; empty = every active admin
        'window_hours' => 24,
        'last_sent_date' => null,
    ];
}

function drLoadSettings(PDO $pdo): array {
    $d = drDefaults();
    try {
        $row = $pdo->query("SELECT * FROM daily_report_settings ORDER BY created_at ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return $d; }
    if (!$row) return $d;
    $j = function ($v, $fallback) { $x = json_decode((string)$v, true); return is_array($x) ? $x : $fallback; };
    return [
        'id' => $row['id'],
        'enabled' => (int)$row['enabled'],
        'send_time' => $row['send_time'] ?: $d['send_time'],
        'days' => $j($row['days'], $d['days']),
        'sections' => array_merge($d['sections'], $j($row['sections'], [])),
        'client_ids' => $j($row['client_ids'], []),
        'recipients' => $j($row['recipients'], []),
        'window_hours' => max(1, (int)($row['window_hours'] ?: 24)),
        'last_sent_date' => $row['last_sent_date'],
    ];
}

// Pull one metric's most recent value out of a snapshot's metrics JSON.
function drMetric(array $metrics, string $arrKey, string $name) {
    foreach (($metrics[$arrKey] ?? []) as $m) {
        if (($m['name'] ?? '') === $name) {
            $vals = $m['values'] ?? [];
            if (!$vals) return null;
            $last = end($vals);
            $v = $last['value'] ?? null;
            return is_numeric($v) ? (float)$v : null;
        }
    }
    return null;
}

function drFmt($n): string {
    $n = (float)$n;
    if (abs($n) >= 1000000) return round($n / 1000000, 1) . 'M';
    if (abs($n) >= 10000) return round($n / 1000, 1) . 'K';
    return number_format($n);
}

function drBuildReport(PDO $pdo, array $s): string {
    $tz = new DateTimeZone('Africa/Cairo');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $h = max(1, (int)$s['window_hours']);
    $sec = $s['sections'];
    $out = ["📊 *Daily report — " . $now->format('D j M') . "*", "_Last {$h} hours_"];

    if (!empty($sec['progress'])) {
        // Brief task progress: finished in the window vs still open, plus who is behind.
        $ex = "'published','scheduled','approved','client_approval','rejected','on_hold'";
        $fin = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE stage = 'published' AND published_at >= (NOW() - INTERVAL {$h} HOUR)");
        $fin->execute();
        $finished = (int)$fin->fetchColumn();
        $steps = $pdo->prepare("SELECT COUNT(*) FROM performance_logs WHERE created_at >= (NOW() - INTERVAL {$h} HOUR) AND user_name IS NOT NULL AND user_name <> ''");
        $steps->execute();
        $stepsDone = (int)$steps->fetchColumn();
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE stage NOT IN ({$ex})")->fetchColumn();
        $od = $pdo->prepare("SELECT COALESCE(tm.name,'Unassigned') AS who, COUNT(*) AS n FROM posts p LEFT JOIN team_members tm ON tm.email = p.assigned_to
            WHERE p.due_date IS NOT NULL AND p.due_date <> '' AND LEFT(p.due_date,10) < :t AND p.stage NOT IN ({$ex}) GROUP BY who ORDER BY n DESC LIMIT 8");
        $od->execute([':t' => $today]);
        $late = $od->fetchAll(PDO::FETCH_ASSOC);
        $out[] = "";
        $out[] = "📌 *Progress* — {$stepsDone} step(s) done · {$finished} published · {$pending} still open";
        if ($late) $out[] = "🔴 Out of timeline: " . implode(', ', array_map(fn($r) => "{$r['who']} ({$r['n']})", $late));
        else $out[] = "🟢 Everyone is within their timeline.";
    }

    if (!empty($sec['accounts_hint'])) {
        // Short hint on accounts needing attention (replaces the separate Mai "quick account check" ping).
        $hints = [];
        $cl = $pdo->query("SELECT id, name FROM clients WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $lp = $pdo->prepare("SELECT MAX(published_at) FROM posts WHERE client_id = :c AND stage = 'published'");
        $sp = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE client_id = :c AND stage = 'scheduled' AND LEFT(scheduled_date,10) >= :t");
        foreach ($cl as $c) {
            $lp->execute([':c' => $c['id']]); $last = $lp->fetchColumn();
            $sp->execute([':c' => $c['id'], ':t' => $today]); $sched = (int)$sp->fetchColumn();
            $why = [];
            if ($last) { $days = (int)floor((time() - strtotime($last)) / 86400); if ($days >= 6) $why[] = "last post {$days}d ago"; }
            if ($sched === 0) $why[] = "nothing scheduled";
            if ($why) $hints[] = "• {$c['name']} — " . implode(', ', $why);
        }
        $out[] = "";
        $out[] = "🩺 *Accounts to watch*" . ($hints ? " — " . count($hints) : '');
        if (!$hints) $out[] = "All accounts look healthy ✅";
        foreach (array_slice($hints, 0, 10) as $h2) $out[] = $h2;
        if (count($hints) > 10) $out[] = "…and " . (count($hints) - 10) . " more";
    }

    if (!empty($sec['tasks_done'])) {
        $st = $pdo->prepare("SELECT user_name, client_name, post_title FROM performance_logs
            WHERE created_at >= (NOW() - INTERVAL {$h} HOUR) AND user_name IS NOT NULL AND user_name <> '' ORDER BY created_at DESC");
        $st->execute();
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $u = $r['user_name'];
            if (!isset($by[$u])) $by[$u] = ['n' => 0, 'clients' => []];
            $by[$u]['n']++;
            if (!empty($r['client_name'])) $by[$u]['clients'][$r['client_name']] = true;
        }
        uasort($by, fn($a, $b) => $b['n'] <=> $a['n']);
        $total = array_sum(array_column($by, 'n'));
        $out[] = "";
        $out[] = "✅ *Work completed* — {$total} step(s)";
        if (!$by) $out[] = "Nothing logged.";
        foreach (array_slice($by, 0, 12, true) as $u => $v) {
            $cl = array_slice(array_keys($v['clients']), 0, 3);
            $out[] = "• {$u}: {$v['n']}" . ($cl ? " (" . implode(', ', $cl) . ")" : '');
        }
    }

    if (!empty($sec['tasks_open'])) {
        $ex = "'published','scheduled','approved','client_approval','rejected','on_hold'";
        $od = $pdo->prepare("SELECT p.title, p.client_name, p.due_date, tm.name AS who FROM posts p
            LEFT JOIN team_members tm ON tm.email = p.assigned_to
            WHERE p.due_date IS NOT NULL AND p.due_date <> '' AND LEFT(p.due_date,10) < :t AND p.stage NOT IN ({$ex})
            ORDER BY p.due_date ASC LIMIT 200");
        $od->execute([':t' => $today]);
        $overdue = $od->fetchAll(PDO::FETCH_ASSOC);
        $dt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE LEFT(due_date,10) = :t AND stage NOT IN ({$ex})");
        $dt->execute([':t' => $today]);
        $dueToday = (int)$dt->fetchColumn();
        $out[] = "";
        $out[] = "⏰ *Tasks* — " . count($overdue) . " overdue · {$dueToday} due today";
        $byWho = [];
        foreach ($overdue as $r) { $w = $r['who'] ?: 'Unassigned'; $byWho[$w] = ($byWho[$w] ?? 0) + 1; }
        arsort($byWho);
        foreach (array_slice($byWho, 0, 8, true) as $w => $n) $out[] = "• {$w}: {$n} overdue";
    }

    if (!empty($sec['posting'])) {
        // Published in the window, scheduled for today / tomorrow, and any failed publish.
        $st = $pdo->prepare("SELECT client_name, platform FROM posts WHERE stage = 'published' AND published_at >= (NOW() - INTERVAL {$h} HOUR)");
        $st->execute();
        $pubBy = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $c = $r['client_name'] ?: 'Unknown'; $pubBy[$c] = ($pubBy[$c] ?? 0) + 1; }
        arsort($pubBy);
        $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
        $sc = $pdo->prepare("SELECT client_name, platform, scheduled_date, scheduled_time FROM posts WHERE stage = 'scheduled' AND LEFT(scheduled_date,10) IN (:t, :n) ORDER BY scheduled_date, scheduled_time");
        $sc->execute([':t' => $today, ':n' => $tomorrow]);
        $schedToday = []; $schedTomorrow = 0;
        foreach ($sc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (substr($r['scheduled_date'], 0, 10) === $today) { $c = $r['client_name'] ?: 'Unknown'; $schedToday[$c] = ($schedToday[$c] ?? 0) + 1; }
            else $schedTomorrow++;
        }
        arsort($schedToday);
        $fl = $pdo->prepare("SELECT client_name, title FROM posts WHERE publish_error IS NOT NULL AND publish_error <> '' AND stage <> 'published' ORDER BY created_at DESC LIMIT 6");
        $fl->execute();
        $failed = $fl->fetchAll(PDO::FETCH_ASSOC);
        $out[] = "";
        $out[] = "📮 *Posting* — " . array_sum($pubBy) . " published · " . array_sum($schedToday) . " scheduled today · {$schedTomorrow} tomorrow";
        foreach (array_slice($pubBy, 0, 8, true) as $c => $n) $out[] = "• {$c}: {$n} published";
        foreach (array_slice($schedToday, 0, 6, true) as $c => $n) $out[] = "• {$c}: {$n} going out today";
        foreach ($failed as $r) $out[] = "⚠️ Failed to publish: " . ($r['client_name'] ?: '') . " — " . mb_substr((string)$r['title'], 0, 40);
    }

    if (!empty($sec['insights'])) {
        $ids = array_values(array_filter($s['client_ids']));
        $sql = "SELECT client_id, client_name, platform, snapshot_date, metrics FROM meta_insights_snapshots WHERE snapshot_date >= (CURDATE() - INTERVAL 8 DAY)";
        $params = [];
        if ($ids) {
            $ph = implode(',', array_map(fn($i) => ':c' . $i, array_keys($ids)));
            $sql .= " AND client_id IN ({$ph})";
            foreach ($ids as $i => $id) $params[':c' . $i] = $id;
        }
        $sql .= " ORDER BY snapshot_date ASC";
        $st = $pdo->prepare($sql); $st->execute($params);
        $groups = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $groups[$r['client_name'] . '|' . $r['platform']][] = $r;
        }
        $lines = [];
        foreach ($groups as $key => $rows) {
            [$client, $plat] = explode('|', $key, 2);
            $arrKey = $plat === 'facebook' ? 'page_insights' : 'ig_insights';
            $reachName = $plat === 'facebook' ? 'page_views_total' : 'reach';
            $first = json_decode(reset($rows)['metrics'] ?? '{}', true) ?: [];
            $lastR = json_decode(end($rows)['metrics'] ?? '{}', true) ?: [];
            $fNow = drMetric($lastR, $arrKey, 'followers_count');
            $fOld = drMetric($first, $arrKey, 'followers_count');
            $reach = 0; $hasReach = false;
            foreach (array_slice($rows, -7) as $r) {
                $m = json_decode($r['metrics'] ?? '{}', true) ?: [];
                $v = drMetric($m, $arrKey, $reachName);
                if ($v !== null) { $reach += $v; $hasReach = true; }
            }
            $bits = [];
            if ($fNow !== null) $bits[] = drFmt($fNow) . ' followers' . ($fOld !== null && $fOld != $fNow ? ' (' . ($fNow - $fOld >= 0 ? '+' : '') . drFmt($fNow - $fOld) . ' wk)' : '');
            if ($hasReach) $bits[] = drFmt($reach) . ' ' . ($plat === 'facebook' ? 'views' : 'reach') . ' (7d)';
            if ($bits) $lines[] = "• {$client} " . ($plat === 'facebook' ? 'FB' : 'IG') . ": " . implode(' · ', $bits);
        }
        $out[] = "";
        $out[] = "📈 *Account insights*";
        if (!$lines) $out[] = "No insight data yet.";
        foreach (array_slice($lines, 0, 14) as $l) $out[] = $l;
    }

    if (!empty($sec['applications'])) {
        $st = $pdo->prepare("SELECT candidate_name, job_title, ai_score FROM job_applications WHERE created_at >= (NOW() - INTERVAL {$h} HOUR) ORDER BY created_at DESC");
        $st->execute();
        $apps = $st->fetchAll(PDO::FETCH_ASSOC);
        $unrev = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'new'")->fetchColumn();
        $out[] = "";
        $out[] = "👥 *New applications* — " . count($apps) . " new · {$unrev} awaiting review";
        foreach (array_slice($apps, 0, 8) as $a) {
            $out[] = "• " . ($a['candidate_name'] ?: 'Unknown') . " — " . ($a['job_title'] ?: 'role') . ($a['ai_score'] !== null ? " ({$a['ai_score']}/100)" : '');
        }
        if (count($apps) > 8) $out[] = "…and " . (count($apps) - 8) . " more";
    }

    $text = implode("\n", $out);
    return mb_strlen($text) > 3800 ? mb_substr($text, 0, 3790) . "…" : $text;
}

// Who receives it: the chosen team members, or every active admin.
function drRecipients(PDO $pdo, array $s): array {
    $emails = array_values(array_filter($s['recipients']));
    if ($emails) {
        $ph = implode(',', array_map(fn($i) => ':e' . $i, array_keys($emails)));
        $st = $pdo->prepare("SELECT name, whatsapp_number FROM team_members WHERE status = 'active' AND email IN ({$ph}) AND whatsapp_number IS NOT NULL AND whatsapp_number <> ''");
        foreach ($emails as $i => $e) $st->bindValue(':e' . $i, $e);
        $st->execute();
    } else {
        $st = $pdo->query("SELECT name, whatsapp_number FROM team_members WHERE status = 'active' AND role = 'admin' AND whatsapp_number IS NOT NULL AND whatsapp_number <> ''");
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function drSend(PDO $pdo, array $s): array {
    $text = drBuildReport($pdo, $s);
    $sent = 0;
    $rec = drRecipients($pdo, $s);
    foreach ($rec as $r) { sendWhatsAppReply($r['whatsapp_number'], $text); $sent++; }
    return ['sent_to' => $sent, 'recipients' => array_column($rec, 'name')];
}
