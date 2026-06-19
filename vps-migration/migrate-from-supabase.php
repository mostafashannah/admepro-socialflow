<?php
// ================================================================
// One-time data migration: pulls every row out of the old Supabase
// project via its REST API and inserts it into the local MySQL
// database (same schema, same table/column names — see mysql-schema.sql).
//
// Run on the VPS, once, after api.php/storage.php are already live, from
// the project root:
//   php vps-migration/migrate-from-supabase.php
//
// Safe to re-run: existing rows (matched by primary key `id`) are
// skipped via INSERT IGNORE, so a re-run only picks up rows added
// to Supabase since the last run.
// ================================================================

require_once dirname(__DIR__) . '/config.php';

const OLD_SB_URL = 'https://qkkplekuknuxsqvkynna.supabase.co/rest/v1';
const OLD_SB_KEY = 'sb_publishable_4tj9trWNbP0X4DkNJii5aQ_gh6RHfGw';

// The 38 real Supabase tables (excludes the 7 stub tables that never
// existed in Supabase — see README.md).
const TABLES = [
    'posts','projects','clients','team_members','comments','assets','time_logs',
    'notifications','notification_prefs','templates','quotes','leads','lead_activities',
    'invoices','payments','integrations','integration_logs','subscriptions',
    'subscription_payments','app_settings','email_settings','branding_assets',
    'user_profiles','client_knowledge','client_documents','performance_logs',
    'ai_insights','time_entries','schedule_overrides','client_contracts',
    'client_intelligence','content_pillars','user_invitations','access_requests',
    'client_users','client_tasks','client_memory','email_logs','activity_logs',
];

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function ident($name) {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('Invalid identifier: ' . $name);
    }
    return '`' . $name . '`';
}

function fetchSupabasePage($table, $limit, $offset) {
    $url = OLD_SB_URL . '/' . $table . '?select=*&limit=' . $limit . '&offset=' . $offset . '&order=id.asc';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . OLD_SB_KEY,
            'Authorization: Bearer ' . OLD_SB_KEY,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new RuntimeException("$table: curl error: $err");
    if ($status !== 200) throw new RuntimeException("$table: Supabase returned HTTP $status: $body");
    $rows = json_decode($body, true);
    if (!is_array($rows)) throw new RuntimeException("$table: unexpected response: $body");
    return $rows;
}

$totalInserted = 0;
$totalSkipped = 0;

foreach (TABLES as $table) {
    $stmt = $pdo->query('DESCRIBE ' . ident($table));
    $validCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $validCols = array_flip($validCols);

    $limit = 1000;
    $offset = 0;
    $tableFetched = 0;
    $tableInserted = 0;

    while (true) {
        $rows = fetchSupabasePage($table, $limit, $offset);
        if (!$rows) break;
        $tableFetched += count($rows);

        foreach ($rows as $row) {
            $record = array_intersect_key($row, $validCols);
            if (!$record || empty($record['id'])) continue;

            $cols = array_keys($record);
            $colSql = implode(',', array_map('ident', $cols));
            $placeholders = implode(',', array_map(fn($c) => ':' . $c, $cols));
            $sql = "INSERT IGNORE INTO " . ident($table) . " ($colSql) VALUES ($placeholders)";
            $bind = [];
            foreach ($record as $k => $v) {
                $bind[':' . $k] = is_array($v) ? json_encode($v) : $v;
            }
            $ins = $pdo->prepare($sql);
            $ins->execute($bind);
            if ($ins->rowCount() > 0) $tableInserted++;
        }

        if (count($rows) < $limit) break;
        $offset += $limit;
    }

    $tableSkipped = $tableFetched - $tableInserted;
    $totalInserted += $tableInserted;
    $totalSkipped += $tableSkipped;
    echo sprintf("%-22s fetched=%-5d inserted=%-5d skipped(existing)=%-5d\n", $table, $tableFetched, $tableInserted, $tableSkipped);
}

echo "\nDone. Total inserted=$totalInserted, skipped(already present)=$totalSkipped\n";
