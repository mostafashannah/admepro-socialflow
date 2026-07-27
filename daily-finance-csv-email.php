<?php
/**
 * daily-finance-csv-email.php — emails the full expenses ledger (every
 * income/expense record on file, not just new ones) as a CSV attachment to
 * every active admin, once per day.
 *
 * CLI-only, like daily-email-cron.php — run once a day via cron, e.g.:
 *   0 7 * * * /usr/bin/php /var/www/socialflow/daily-finance-csv-email.php >> /var/www/socialflow/daily-finance-csv-email.log 2>&1
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$app = $pdo->query("SELECT * FROM app_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$appName = $app['app_name'] ?: 'SocialFlow';

$admins = $pdo->query("SELECT email, name FROM team_members WHERE role = 'admin' AND status = 'active' AND email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_ASSOC);
if (!$admins) { echo json_encode(['sent'=>false,'reason'=>'no_active_admins']); exit; }

$rows = $pdo->query("
    SELECT e.date, e.type, e.category, e.description, e.amount, e.currency, e.method,
           e.check_no, e.ref, e.created_by, tm.name AS team_member_name
    FROM expenses e
    LEFT JOIN team_members tm ON tm.id = e.team_member_id
    ORDER BY e.date DESC, e.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Build the CSV in-memory (no temp file needed — small enough to hold as a string).
$fh = fopen('php://temp', 'r+');
fputcsv($fh, ['Date', 'Type', 'Category', 'Description', 'Amount', 'Currency', 'Method', 'Check No', 'Reference', 'Team Member', 'Recorded By']);
foreach ($rows as $r) {
    fputcsv($fh, [
        $r['date'], $r['type'] === 'in' ? 'Income' : 'Expense', $r['category'], $r['description'],
        $r['amount'], $r['currency'], $r['method'], $r['check_no'], $r['ref'],
        $r['team_member_name'], $r['created_by'],
    ]);
}
rewind($fh);
$csv = stream_get_contents($fh);
fclose($fh);

$today = date('Y-m-d');

// Today's income/expense totals from the ledger.
$todayTotals = $pdo->query("
    SELECT type, COALESCE(SUM(amount),0) AS total
    FROM expenses
    WHERE date = CURDATE()
    GROUP BY type
")->fetchAll(PDO::FETCH_KEY_PAIR);
$todayIn  = (float)($todayTotals['in'] ?? 0);
$todayOut = (float)($todayTotals['out'] ?? 0);

// Payments collected today against outstanding liabilities (team-member advances / installments).
$todayCollected = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM outstanding_payments WHERE date = CURDATE()
")->fetchColumn();

// Current outstanding balance: total_payable minus payments made so far, for
// liabilities not yet fully settled.
$outstandingBalance = (float)$pdo->query("
    SELECT COALESCE(SUM(l.total_payable - COALESCE(p.paid, 0)), 0)
    FROM outstanding_liabilities l
    LEFT JOIN (
        SELECT liability_id, SUM(amount) AS paid FROM outstanding_payments GROUP BY liability_id
    ) p ON p.liability_id = l.id
    WHERE l.status IN ('outstanding', 'partial')
")->fetchColumn();

$fmt = fn($n) => number_format((float)$n, 2);

$filename = "finance_ledger_{$today}.csv";
$subject = "{$appName} — Daily Finance Export ({$today})";
$html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#333">'
    . '<h3 style="margin:0 0 8px">Today\'s Summary (' . htmlspecialchars($today) . ')</h3>'
    . '<table style="border-collapse:collapse;margin-bottom:16px">'
    . '<tr><td style="padding:4px 12px 4px 0;color:#666">Income (in)</td><td style="padding:4px 0;font-weight:bold;color:#1a7f37">' . $fmt($todayIn) . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0;color:#666">Expenses (out)</td><td style="padding:4px 0;font-weight:bold;color:#c0392b">' . $fmt($todayOut) . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0;color:#666">Payments Collected</td><td style="padding:4px 0;font-weight:bold">' . $fmt($todayCollected) . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0;color:#666">Outstanding Balance</td><td style="padding:4px 0;font-weight:bold">' . $fmt($outstandingBalance) . '</td></tr>'
    . '</table>'
    . '<p>Attached is the full finance ledger as of ' . htmlspecialchars($today) . ' — '
    . count($rows) . ' record' . (count($rows) === 1 ? '' : 's') . ' in total.</p>'
    . '<p style="font-size:12px;color:#999">Automated daily export from ' . htmlspecialchars($appName) . '.</p>'
    . '</div>';

function send_with_attachment($to, $subject, $html, $filename, $csvContent) {
    $payload = json_encode([
        "from"        => "SocialFlow <noreply@admepro.com>",
        "to"          => [$to],
        "subject"     => $subject,
        "html"        => $html,
        "attachments" => [[
            "filename" => $filename,
            "content"  => base64_encode($csvContent),
        ]],
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
    $err    = curl_error($ch);
    curl_close($ch);
    return [$status >= 200 && $status < 300, $err ?: $res];
}

$insertLog = $pdo->prepare("INSERT INTO email_logs (id, `to`, subject, from_name, status, error_message, sent_at) VALUES (UUID(), :to, :subject, :from_name, :status, :error_message, :sent_at)");
$sentCount = 0;
$nowStr = date('Y-m-d H:i:s');

foreach ($admins as $admin) {
    [$ok, $raw] = send_with_attachment($admin['email'], $subject, $html, $filename, $csv);
    $insertLog->execute([
        ':to' => $admin['email'], ':subject' => $subject, ':from_name' => 'SocialFlow',
        ':status' => $ok ? 'sent' : 'failed', ':error_message' => $ok ? '' : $raw,
        ':sent_at' => $nowStr,
    ]);
    if ($ok) $sentCount++;
}

echo json_encode(['sent'=>true,'admins_checked'=>count($admins),'sent_count'=>$sentCount,'records'=>count($rows)]);
