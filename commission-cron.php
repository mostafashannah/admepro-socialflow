<?php
/**
 * commission-cron.php — daily cron that auto-creates a Commission career
 * event (+ linked Salaries & Payroll expense) for every account manager
 * with commission terms set on a client, at the end of each commission
 * cycle (monthly or quarterly).
 *
 * Setup (same pattern as meta-insights-cron.php):
 *   Cron command: php /var/www/socialflow/commission-cron.php
 *   Schedule: once daily, e.g. 0 3 * * *
 *
 * Only actually does anything on the FIRST DAY of a new cycle (the 1st of
 * every month for "monthly" managers; the 1st of Jan/Apr/Jul/Oct for
 * "quarterly" managers) — every other day it's a no-op. Idempotent: skips
 * a client/manager/cycle combo that already has a matching event, so
 * re-running the same day twice (or a missed day caught up later) never
 * double-pays.
 */

// CLI-only — this script writes real payroll/financial records and has no
// authentication of its own, so it must never be reachable over plain HTTP
// (this file sits in the public web root alongside the app, like every
// other *-cron.php).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$today = new DateTime('today');
$dayOfMonth = (int)$today->format('j');
$monthNum = (int)$today->format('n');

$isMonthlyTrigger = ($dayOfMonth === 1);
$isQuarterlyTrigger = ($dayOfMonth === 1 && in_array($monthNum, [1,4,7,10], true));

if (!$isMonthlyTrigger && !$isQuarterlyTrigger) {
    echo "Not the 1st of a cycle — nothing to do today.\n";
    exit;
}

// Previous calendar month range [start, end)
$prevMonthStart = (clone $today)->modify('first day of last month')->setTime(0,0,0);
$thisMonthStart = (clone $today)->modify('first day of this month')->setTime(0,0,0);

// Previous calendar quarter range [start, end)
$qStartMonth = intdiv($monthNum - 1, 3) * 3 + 1; // this quarter's start month (1,4,7,10)
$thisQuarterStart = new DateTime("{$today->format('Y')}-" . str_pad($qStartMonth, 2, '0', STR_PAD_LEFT) . "-01");
$prevQuarterStart = (clone $thisQuarterStart)->modify('-3 months');

function client_payments_total(PDO $pdo, string $clientName, DateTime $start, DateTime $end): float {
    $s = $start->format('Y-m-d H:i:s');
    $e = $end->format('Y-m-d H:i:s');

    // Invoice payments attributed via invoices.client_name
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.amount),0) FROM payments p
         JOIN invoices i ON i.invoice_number = p.invoice_number
         WHERE i.client_name = :name AND p.payment_date >= :s AND p.payment_date < :e"
    );
    $stmt->execute([':name'=>$clientName, ':s'=>$s, ':e'=>$e]);
    $total = (float)$stmt->fetchColumn();

    // Subscription payments
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM subscription_payments
         WHERE client_name = :name AND payment_date >= :s AND payment_date < :e"
    );
    $stmt->execute([':name'=>$clientName, ':s'=>$s, ':e'=>$e]);
    $total += (float)$stmt->fetchColumn();

    // Manual client-payment income entries (type='in', category='client_payment')
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM expenses
         WHERE type = 'in' AND category = 'client_payment' AND description = :name
           AND date >= :s AND date < :e"
    );
    $stmt->execute([':name'=>$clientName, ':s'=>$s, ':e'=>$e]);
    $total += (float)$stmt->fetchColumn();

    return $total;
}

// Mirrors calcUserPerf/calcAllPerf's scoring formula in app.jsx (task
// performance blended 70/30 with Mai check-in reliability where it
// applies, minus the overdue-task penalty) so the multiplier below is
// judging the same number an admin sees on that AM's Scoring tab — not a
// separate, drifting definition of "performance".
function calc_am_score(PDO $pdo, string $managerId, string $managerEmail): array {
    $logsStmt = $pdo->prepare("SELECT on_time, rejected, quality_score, revision_count FROM performance_logs WHERE user_email = :email");
    $logsStmt->execute([':email' => $managerEmail]);
    $rows = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);
    $taskScore = 0;
    if ($total > 0) {
        $onTime = 0; $rejected = 0; $qualitySum = 0.0; $revSum = 0.0;
        foreach ($rows as $r) {
            if ((int)$r['on_time']) $onTime++;
            if ((int)$r['rejected']) $rejected++;
            $qualitySum += (float)$r['quality_score'];
            $revSum += (float)$r['revision_count'];
        }
        $completionRate = round((($total - $rejected) / $total) * 100);
        $onTimeRate = round(($onTime / $total) * 100);
        $avgQuality = round($qualitySum / $total);
        $avgRevisions = round($revSum / $total, 1);
        $revScore = max(0, 100 - $avgRevisions * 18);
        $taskScore = min(100, round($completionRate * 0.25 + $onTimeRate * 0.20 + $avgQuality * 0.30 + $revScore * 0.25));
    }

    $maiStmt = $pdo->prepare("SELECT score, max_score, status FROM mai_report_sessions WHERE account_manager_id = :id AND score IS NOT NULL AND max_score IS NOT NULL AND max_score > 0");
    $maiStmt->execute([':id' => $managerId]);
    $maiRows = $maiStmt->fetchAll(PDO::FETCH_ASSOC);
    $maiScore = null;
    if (count($maiRows) > 0) {
        $sumPct = 0.0; $weight = 0;
        foreach ($maiRows as $m) {
            $w = ($m['status'] === 'missed') ? 2 : 1;
            $sumPct += ((float)$m['score'] / (float)$m['max_score'] * 100) * $w;
            $weight += $w;
        }
        $maiScore = round($sumPct / $weight);
    }

    $score = $total === 0
        ? ($maiScore ?? 0)
        : ($maiScore !== null ? round($taskScore * 0.7 + $maiScore * 0.3) : $taskScore);

    // Account managers have no ROLE_OWNED_STAGE, so any of their assigned
    // posts still not complete past its due date counts — same rule as
    // countOverdueTasks()/applyOverduePenalty() in app.jsx.
    $today = (new DateTime('today'))->format('Y-m-d');
    $odStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM posts WHERE assigned_to = :email AND due_date IS NOT NULL AND due_date <> '' AND due_date < :today
         AND stage NOT IN ('published','scheduled','rejected','on_hold')"
    );
    $odStmt->execute([':email' => $managerEmail, ':today' => $today]);
    $overdueCount = (int)$odStmt->fetchColumn();
    $score = max(0, $score - min(40, $overdueCount * 8));

    return ['score' => (int)$score, 'overdue' => $overdueCount];
}

// Score-gated payout — a low score means the commission isn't earned at
// all, not just docked a little:
//   <=60 -> 0%, 60-80 -> 50%, 80-90 -> 75%, >90 -> 100%
function commission_multiplier(int $score): float {
    if ($score <= 60) return 0.0;
    if ($score <= 80) return 0.5;
    if ($score <= 90) return 0.75;
    return 1.0;
}

$clients = $pdo->query(
    "SELECT id, name, account_manager_commissions FROM clients
     WHERE account_manager_commissions IS NOT NULL AND account_manager_commissions <> '' AND account_manager_commissions <> '{}'"
)->fetchAll(PDO::FETCH_ASSOC);

$eventExists = $pdo->prepare(
    "SELECT COUNT(*) FROM team_member_events WHERE team_member_id = :mid AND event_type = 'commission' AND title = :title"
);
$insertEvent = $pdo->prepare(
    "INSERT INTO team_member_events (id, team_member_id, team_member_name, event_type, title, amount, effective_date, notes, recorded_by)
     VALUES (UUID(), :mid, :mname, 'commission', :title, :amount, :eff, :notes, 'System (Commission Cron)')"
);
$insertExpense = $pdo->prepare(
    "INSERT INTO expenses (id, type, category, description, amount, currency, date, team_member_id, source)
     VALUES (UUID(), 'out', 'salaries', :desc, :amount, 'EGP', :date, :mid, 'app')"
);

$teamStmt = $pdo->prepare("SELECT id, name, email FROM team_members WHERE id = :id");

$created = 0;
foreach ($clients as $client) {
    $commissions = json_decode($client['account_manager_commissions'] ?? '{}', true) ?: [];
    foreach ($commissions as $managerId => $terms) {
        $pct = (float)($terms['percentage'] ?? 0);
        $cycle = ($terms['cycle'] ?? 'monthly') === 'quarterly' ? 'quarterly' : 'monthly';
        if ($pct <= 0) continue;
        if ($cycle === 'monthly' && !$isMonthlyTrigger) continue;
        if ($cycle === 'quarterly' && !$isQuarterlyTrigger) continue;

        $start = $cycle === 'quarterly' ? $prevQuarterStart : $prevMonthStart;
        $end   = $cycle === 'quarterly' ? $thisQuarterStart : $thisMonthStart;
        $cycleLabel = $cycle === 'quarterly'
            ? 'Q' . (intdiv(((int)$start->format('n')) - 1, 3) + 1) . ' ' . $start->format('Y')
            : $start->format('F Y');

        $total = client_payments_total($pdo, $client['name'], $start, $end);
        $rawCommission = round($total * $pct / 100, 2);
        if ($rawCommission <= 0) continue;

        $teamStmt->execute([':id'=>$managerId]);
        $manager = $teamStmt->fetch(PDO::FETCH_ASSOC);
        if (!$manager) continue;

        $scoreInfo = calc_am_score($pdo, $managerId, $manager['email'] ?? '');
        $multiplier = commission_multiplier($scoreInfo['score']);
        $commission = round($rawCommission * $multiplier, 2);
        if ($commission <= 0) continue; // score at or below 60 — commission not earned this cycle

        $title = "Commission — {$client['name']} ({$cycleLabel})";
        $eventExists->execute([':mid'=>$managerId, ':title'=>$title]);
        if ((int)$eventExists->fetchColumn() > 0) continue; // already created — idempotent

        $notes = "{$pct}% of EGP " . number_format($total, 2) . " in {$cycleLabel} client payments "
            . "= EGP " . number_format($rawCommission, 2) . " raw, scaled to " . number_format($multiplier * 100, 0)
            . "% (score {$scoreInfo['score']}" . ($scoreInfo['overdue'] > 0 ? ", {$scoreInfo['overdue']} overdue task(s)" : "") . ").";
        $insertEvent->execute([
            ':mid'=>$managerId, ':mname'=>$manager['name'], ':title'=>$title,
            ':amount'=>$commission, ':eff'=>$today->format('Y-m-d'), ':notes'=>$notes,
        ]);
        $insertExpense->execute([
            ':desc'=>$title, ':amount'=>$commission, ':date'=>$today->format('Y-m-d'), ':mid'=>$managerId,
        ]);
        $created++;
    }
}

echo "Commission events created: {$created}\n";
