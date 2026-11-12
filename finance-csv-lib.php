<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }
/** finance-csv-lib.php — builds the full finance ledger CSV + today's summary (used in the admin end-of-day email). */
function fin_build(PDO $pdo, $appName = 'SocialFlow') {
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

    // Current outstanding balance: outstanding_total_payable minus payments made
    // so far, for expense rows not yet fully settled. Outstanding tracking lives
    // directly on `expenses` (outstanding_kind/outstanding_status/outstanding_total_payable),
    // not a separate liabilities table.
    $outstandingBalance = (float)$pdo->query("
        SELECT COALESCE(SUM(e.outstanding_total_payable - COALESCE(p.paid, 0)), 0)
        FROM expenses e
        LEFT JOIN (
            SELECT expense_id, SUM(amount) AS paid FROM outstanding_payments GROUP BY expense_id
        ) p ON p.expense_id = e.id
        WHERE e.outstanding_status IN ('outstanding', 'partial')
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


    return ['csv' => $csv, 'html' => $html, 'filename' => $filename, 'count' => count($rows)];
}

function fin_send_with_attachment($to, $subject, $html, $filename, $content) {
    $ch = curl_init("https://api.resend.com/emails");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => json_encode(["from" => "SocialFlow <noreply@admepro.com>", "to" => [$to], "subject" => $subject, "html" => $html,
            "attachments" => $filename ? [["filename" => $filename, "content" => base64_encode($content)]] : []]),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . RESEND_API_KEY, "Content-Type: application/json"]]);
    $res = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return [$status >= 200 && $status < 300, $err ?: $res];
}
