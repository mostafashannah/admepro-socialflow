<?php
// ================================================================
// SocialFlow — Generic REST API over MySQL
// Mimics the subset of PostgREST conventions app.jsx already speaks,
// so only SB_URL / SB_KEY in app.jsx need to change — no UI rewrite.
//
// URL shape (after the rewrite rules in nginx.conf.example / .htaccess):
//   GET    /api/{table}?select=*&col=eq.val&order=col.desc&limit=50
//   POST   /api/{table}                (+ header Prefer: return=representation)
//   PATCH  /api/{table}?id=eq.{id}      (+ header Prefer: return=representation)
//   DELETE /api/{table}?id=eq.{id}
//
// Auth: same idea as Supabase's anon key — a single shared key checked
// against the "apikey" header. Defined in config.php as API_KEY.
// ================================================================

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, apikey, Authorization, Prefer, X-SF-Session');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Accepts a real login session (X-SF-Session, see auth.php) or — for now —
// the legacy shared key. $AUTH says which; stage 1 grants both the same access.
require_once __DIR__ . '/auth-lib.php';
$AUTH = authResolve();
// Not signed in: only the look of the app (colours/logo/name) may be read, with anything secret removed.
$ANON = !$AUTH && $_SERVER['REQUEST_METHOD'] === 'GET' && in_array($_GET['table'] ?? '', ['app_settings', 'branding_assets'], true);
if ($ANON) { require_once __DIR__ . '/guest-policy.php'; $AUTH = ['kind' => 'anon']; }
if (!$AUTH) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}
// Guest pass (public link pages): may only touch the one record its link opens — see guest-policy.php.
$GUEST = ($AUTH['kind'] === 'session' && ($AUTH['session']['user_type'] ?? '') === 'guest') ? $AUTH['session'] : null;
if ($GUEST) require_once __DIR__ . '/guest-policy.php';
// Logged-in team member / client: what their role allows — see role-policy.php.
$TEAM = ($AUTH['kind'] === 'session' && ($AUTH['session']['user_type'] ?? '') === 'team') ? $AUTH['session'] : null;
$CLIENT = ($AUTH['kind'] === 'session' && ($AUTH['session']['user_type'] ?? '') === 'client') ? $AUTH['session'] : null;
if ($TEAM || $CLIENT) require_once __DIR__ . '/role-policy.php';

$ALLOWED_TABLES = [
    'posts','projects','clients','team_members','comments','assets','time_logs',
    'notifications','notification_prefs','templates','quotes','leads','lead_activities',
    'invoices','payments','integrations','integration_logs','subscriptions',
    'subscription_payments','app_settings','email_settings','branding_assets',
    'user_profiles','client_knowledge','client_documents','performance_logs',
    'ai_insights','time_entries','schedule_overrides','client_contracts',
    'client_intelligence','content_pillars','user_invitations','access_requests',
    'client_users','client_tasks','client_memory','email_logs','activity_logs',
    'generated_leads','lead_agent_configs','agent_configs','agent_logs',
    'agent_runs','system_sessions','monthly_briefs','push_subscriptions',
    'meta_insights_snapshots','customer_messages','reply_bot_settings',
    'role_permissions','leave_requests','attendance_records','contact_reports',
    'lead_notify_settings','expenses','finance_client_notes',
    'job_openings','job_applications','deleted_email_applications','job_application_activity',
    'outstanding_liabilities','outstanding_payments','team_member_events',
    'pro_chat_sessions','contact_report_activity','mai_report_sessions',
    'leave_credit_events','payroll_runs','client_approval_links','daily_report_settings','mai_report_settings','recurring_tasks','task_logs',
];

$table = $_GET['table'] ?? '';
if (!in_array($table, $ALLOWED_TABLES, true)) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown table: ' . $table]);
    exit;
}

$pdo = authPdo(); // same connection settings as before (auth-lib.php)

// Quote a SQL identifier (table/column name) safely.
function ident($name) {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('Invalid identifier: ' . $name);
    }
    return '`' . $name . '`';
}

// Build a WHERE clause from PostgREST-style query params: col=op.value
// Supported ops: eq, neq, gt, gte, lt, lte, like, ilike, in, is
function buildWhere(array $params, array &$bindings) {
    $clauses = [];
    $skip = ['select', 'order', 'limit', 'offset', 'table'];
    $opMap = [
        'eq' => '=', 'neq' => '!=', 'gt' => '>', 'gte' => '>=',
        'lt' => '<', 'lte' => '<=',
    ];
    foreach ($params as $col => $raw) {
        if (in_array($col, $skip, true)) continue;
        if (!preg_match('/^([a-z]+)\.(.*)$/', $raw, $m)) continue;
        [$op, $val] = [$m[1], $m[2]];
        $colSql = ident($col);
        $key = ':w_' . count($bindings);
        if (isset($opMap[$op])) {
            $clauses[] = "$colSql {$opMap[$op]} $key";
            $bindings[$key] = $val;
        } elseif ($op === 'like' || $op === 'ilike') {
            $clauses[] = "$colSql LIKE $key";
            $bindings[$key] = str_replace('*', '%', $val);
        } elseif ($op === 'in') {
            $list = trim($val, '()');
            $items = $list === '' ? [] : explode(',', $list);
            $keys = [];
            foreach ($items as $i => $item) {
                $k = $key . '_' . $i;
                $keys[] = $k;
                $bindings[$k] = trim($item, '"');
            }
            $clauses[] = $keys ? "$colSql IN (" . implode(',', $keys) . ")" : '1=0';
        } elseif ($op === 'is') {
            $clauses[] = strtolower($val) === 'null' ? "$colSql IS NULL" : "$colSql IS NOT NULL";
        }
    }
    return $clauses ? ('WHERE ' . implode(' AND ', $clauses)) : '';
}

function buildOrder($orderParam) {
    if (!$orderParam) return '';
    $parts = [];
    foreach (explode(',', $orderParam) as $piece) {
        if (!preg_match('/^([A-Za-z0-9_]+)\.(asc|desc)$/i', trim($piece), $m)) continue;
        $parts[] = ident($m[1]) . ' ' . strtoupper($m[2]);
    }
    return $parts ? ('ORDER BY ' . implode(', ', $parts)) : '';
}

function wantsRepresentation() {
    $prefer = $_SERVER['HTTP_PREFER'] ?? '';
    return stripos($prefer, 'return=representation') !== false;
}

// Normalize a value for binding: JSON-encode arrays, and convert ISO-8601
// datetime strings (e.g. "2026-06-20T13:55:00.000Z", as sent by JS Date#toISOString)
// to MySQL's "Y-m-d H:i:s" format — MySQL TIMESTAMP/DATETIME columns reject the
// 'T'/'Z' ISO format outright (SQLSTATE 22007).
function normalizeDbValue($v) {
    // PDO's native (non-emulated) MySQL prepares bind PHP bool false as an empty
    // string rather than 0, which TINYINT columns reject outright (SQLSTATE HY000
    // 1366) — bind explicit 0/1 instead.
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_array($v)) return json_encode($v);
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $v)) {
        try {
            $dt = new DateTime($v);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) { return $v; }
    }
    return $v;
}

// Encode JSON-typed values, decode booleans stored as TINYINT, etc.
// PDO returns DECIMAL/numeric columns as PHP strings by default, and
// json_encode faithfully ships them as JSON strings (e.g. "88.00" instead
// of 88). app.jsx does `reduce((a,l)=>a+(l.field||0),0)` all over the
// place for these — with a string operand, JS's `+` silently concatenates
// instead of adding, corrupting every sum/average built from these columns
// (this is what produced the "000000000000" revision count and NaN score
// on the Team Performance table). Cast known numeric columns per table
// back to real PHP int/float before encoding, so the wire format is a
// real JSON number. Deliberately an explicit per-table allowlist, not a
// blanket "looks numeric" heuristic — that would also mangle things like
// phone numbers or national IDs that happen to be all-digits strings.
$NUMERIC_COLUMNS = [
    'performance_logs' => ['duration_hours','revision_count','client_approved','rejected','on_time','quality_score','hour_of_day','day_of_week'],
    'time_logs' => ['duration_minutes'],
    'leads' => ['value'],
    // estimated_minutes defaults to "" client-side (an unfilled number
    // input) — MySQL's DECIMAL column rejects an empty string outright
    // ("Incorrect decimal value: '' ") instead of treating it as NULL,
    // which silently failed the whole INSERT with no _saveError surfaced
    // client-side (fixed separately in app.jsx's b44Create too).
    'posts' => ['estimated_minutes'],
    'team_members' => ['vacation_days_total','vacation_days_used','wfh_days_total','wfh_days_used','personal_leave_hours_total','personal_leave_hours_used','extra_hours_banked'],
    'leave_credit_events' => ['amount'],
    'payroll_runs' => ['base_salary','vacation_overage_days','deduction_amount','net_amount','termination_deduction'],
    'leave_requests' => ['days','hours'],
];

// Numeric columns get "" (an empty/unfilled number input from the client)
// converted to NULL before binding — MySQL's DECIMAL/numeric column types
// reject an empty string outright instead of treating it as absent.
function normalizeNumericEmptyStrings(array $record, string $table) {
    global $NUMERIC_COLUMNS;
    $numericCols = $NUMERIC_COLUMNS[$table] ?? [];
    foreach ($numericCols as $col) {
        if (array_key_exists($col, $record) && $record[$col] === '') $record[$col] = null;
    }
    return $record;
}

function castRow($row, $table = null) {
    global $NUMERIC_COLUMNS;
    $numericCols = $table && isset($NUMERIC_COLUMNS[$table]) ? $NUMERIC_COLUMNS[$table] : [];
    unset($row['password_hash']); // scrambled passwords never leave the server
    foreach ($row as $k => $v) {
        if (is_string($v) && strlen($v) > 0 && ($v[0] === '[' || $v[0] === '{')) {
            $decoded = json_decode($v, true);
            if (json_last_error() === JSON_ERROR_NONE) { $row[$k] = $decoded; continue; }
        }
        if (in_array($k, $numericCols, true) && $v !== null && is_numeric($v)) {
            $row[$k] = (strpos($v, '.') !== false) ? (float)$v : (int)$v;
        }
    }
    return $row;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $bindings = [];
        $where = buildWhere($_GET, $bindings);
        $order = buildOrder($_GET['order'] ?? null);
        $limit = isset($_GET['limit']) ? max(1, min(5000, (int)$_GET['limit'])) : 500;

        if ($CLIENT) {
            $cw = rpClientWhere($pdo, $CLIENT, $table, $bindings);
            if ($cw === null) { echo json_encode([]); exit; }   // nothing in this table belongs to a client
            $where = $where ? ($where . " AND ($cw)") : "WHERE $cw";
        }
        if ($TEAM && !rpTeamTableAllowed($pdo, $TEAM, $table) && $table !== 'payroll_runs') { echo json_encode([]); exit; }
        $sql = "SELECT * FROM " . ident($table) . " $where $order LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($GUEST) $rawRows = guestFilterRows($pdo, $GUEST, $table, $rawRows, $_GET);
        if ($ANON) $rawRows = array_map('guestSafeSettingsRow', $rawRows);
        if ($TEAM) $rawRows = rpTeamFilterRows($pdo, $TEAM, $table, $rawRows);
        if ($CLIENT) $rawRows = rpClientFilterRows($rawRows, $table);
        $rows = array_map(fn($r) => castRow($r, $table), $rawRows);
        echo json_encode($rows);
        exit;
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $records = is_array($body) && isset($body[0]) ? $body : [$body];
        $created = [];
        foreach ($records as $record) {
            if (!is_array($record) || !$record) continue;
            $record = normalizeNumericEmptyStrings($record, $table);
            unset($record['password_hash']);
            if ($GUEST) $record = guestCheckWrite($pdo, $GUEST, $table, 'POST', null, $record);
            $record = sfHashPasswordField($table, $record);
            if ($TEAM) $record = rpTeamCheckWrite($pdo, $TEAM, $table, 'POST', null, $record);
            if ($CLIENT) $record = rpClientCheckWrite($pdo, $CLIENT, $table, 'POST', null, $record);
            if (!$record) continue;
            if (empty($record['id'])) $record['id'] = generateUuid();
            $cols = array_keys($record);
            $colSql = implode(',', array_map('ident', $cols));
            $placeholders = implode(',', array_map(fn($c) => ':' . $c, $cols));
            $sql = "INSERT INTO " . ident($table) . " ($colSql) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $bind = [];
            foreach ($record as $k => $v) {
                $bind[':' . $k] = normalizeDbValue($v);
            }
            $stmt->execute($bind);
            $created[] = $record['id'];
            // A careers guest pass becomes tied to the application it just created.
            if ($GUEST && $table === 'job_applications' && $GUEST['role'] === 'careers') {
                $pdo->prepare("UPDATE auth_sessions SET user_id = ? WHERE token_hash = ?")->execute([$record['id'], $GUEST['token_hash']]);
                $GUEST['user_id'] = $record['id'];
            }
        }
        if (wantsRepresentation()) {
            $ids = $created ?: [null];
            $keys = [];
            $bindings = [];
            foreach ($ids as $i => $id) {
                $k = ':id_' . $i;
                $keys[] = $k;
                $bindings[$k] = $id;
            }
            $sql = "SELECT * FROM " . ident($table) . " WHERE id IN (" . implode(',', $keys) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            $out = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($GUEST && $table === 'job_applications') $out = array_map('guestHideInternal', $out);
            if ($TEAM) $out = rpTeamFilterRows($pdo, $TEAM, $table, $out);
            if ($CLIENT) $out = rpClientFilterRows($out, $table);
            echo json_encode(array_map(fn($r) => castRow($r, $table), $out));
        } else {
            echo json_encode([]);
        }
        exit;
    }

    if ($method === 'PATCH') {
        $id = null;
        if (isset($_GET['id']) && preg_match('/^eq\.(.+)$/', $_GET['id'], $m)) $id = $m[1];
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'PATCH requires ?id=eq.{id}']);
            exit;
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !$body) {
            echo json_encode([]);
            exit;
        }
        $body = normalizeNumericEmptyStrings($body, $table);
        unset($body['password_hash']);
        if ($GUEST) $body = guestCheckWrite($pdo, $GUEST, $table, 'PATCH', $id, $body);
        $body = sfHashPasswordField($table, $body);
        if ($TEAM) $body = rpTeamCheckWrite($pdo, $TEAM, $table, 'PATCH', $id, $body);
        if ($CLIENT) $body = rpClientCheckWrite($pdo, $CLIENT, $table, 'PATCH', $id, $body);
        if (!$body) { echo json_encode([]); exit; }
        $sets = [];
        $bind = [':id' => $id];
        foreach ($body as $k => $v) {
            $sets[] = ident($k) . ' = :s_' . $k;
            $bind[':s_' . $k] = normalizeDbValue($v);
        }
        $sql = "UPDATE " . ident($table) . " SET " . implode(',', $sets) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        if (wantsRepresentation()) {
            $stmt = $pdo->prepare("SELECT * FROM " . ident($table) . " WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $out = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($GUEST) $out = array_map(fn($r) => guestHideInternal(guestSafeSettingsRow($r)), $out);
            if ($TEAM) $out = rpTeamFilterRows($pdo, $TEAM, $table, $out);
            if ($CLIENT) $out = rpClientFilterRows($out, $table);
            echo json_encode(array_map(fn($r) => castRow($r, $table), $out));
        } else {
            echo json_encode([]);
        }
        exit;
    }

    if ($method === 'DELETE' && $GUEST) guestDeny();
    if ($method === 'DELETE' && ($TEAM || $CLIENT)) {
        $delId = (isset($_GET['id']) && preg_match('/^eq\.(.+)$/', $_GET['id'], $dm)) ? $dm[1] : null;
        if ($TEAM) rpTeamCheckWrite($pdo, $TEAM, $table, 'DELETE', $delId, []);
        if ($CLIENT) rpClientCheckWrite($pdo, $CLIENT, $table, 'DELETE', $delId, []);
    }
    if ($method === 'DELETE') {
        $id = null;
        if (isset($_GET['id']) && preg_match('/^eq\.(.+)$/', $_GET['id'], $m)) $id = $m[1];
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'DELETE requires ?id=eq.{id}']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM " . ident($table) . " WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode([]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// A password typed in the app (new member, invitation accepted, reset, "change password") is stored
// only as a scrambled hash — the readable column is left empty.
function sfHashPasswordField($table, array $rec) {
    if (!in_array($table, ['team_members', 'client_users'], true) || !array_key_exists('password', $rec)) return $rec;
    $pw = (string)$rec['password'];
    if ($pw === '') { unset($rec['password']); return $rec; } // empty = "leave unchanged"
    global $pdo;
    try { $pdo->query("SELECT password_hash FROM `$table` LIMIT 0"); }
    catch (Throwable $e) { try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN password_hash VARCHAR(255) NULL"); } catch (Throwable $e2) {} }
    $rec['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
    $rec['password'] = '';
    return $rec;
}

function generateUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
