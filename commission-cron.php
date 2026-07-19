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

$teamStmt = $pdo->prepare("SELECT id, name FROM team_members WHERE id = :id");

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
        $commission = round($total * $pct / 100, 2);
        if ($commission <= 0) continue;

        $teamStmt->execute([':id'=>$managerId]);
        $manager = $teamStmt->fetch(PDO::FETCH_ASSOC);
        if (!$manager) continue;

        $title = "Commission — {$client['name']} ({$cycleLabel})";
        $eventExists->execute([':mid'=>$managerId, ':title'=>$title]);
        if ((int)$eventExists->fetchColumn() > 0) continue; // already created — idempotent

        $notes = "{$pct}% of EGP " . number_format($total, 2) . " in {$cycleLabel} client payments.";
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
