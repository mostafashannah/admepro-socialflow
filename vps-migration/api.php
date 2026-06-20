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
header('Access-Control-Allow-Headers: Content-Type, apikey, Authorization, Prefer');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$providedKey = $_SERVER['HTTP_APIKEY'] ?? '';
if (!$providedKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $providedKey = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
}
if (!hash_equals(API_KEY, (string)$providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

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
    'meta_insights_snapshots',
];

$table = $_GET['table'] ?? '';
if (!in_array($table, $ALLOWED_TABLES, true)) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown table: ' . $table]);
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

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
function castRow($row) {
    foreach ($row as $k => $v) {
        if (is_string($v) && strlen($v) > 0 && ($v[0] === '[' || $v[0] === '{')) {
            $decoded = json_decode($v, true);
            if (json_last_error() === JSON_ERROR_NONE) $row[$k] = $decoded;
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

        $sql = "SELECT * FROM " . ident($table) . " $where $order LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $rows = array_map('castRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode($rows);
        exit;
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $records = is_array($body) && isset($body[0]) ? $body : [$body];
        $created = [];
        foreach ($records as $record) {
            if (!is_array($record) || !$record) continue;
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
            echo json_encode(array_map('castRow', $stmt->fetchAll(PDO::FETCH_ASSOC)));
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
            echo json_encode(array_map('castRow', $stmt->fetchAll(PDO::FETCH_ASSOC)));
        } else {
            echo json_encode([]);
        }
        exit;
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

function generateUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
