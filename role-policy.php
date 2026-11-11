<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * role-policy.php — what a logged-in TEAM member or CLIENT may read/change
 * through api.php (security stage 2c). Mirrors the app's own permission
 * model (DEFAULT_ROLE_PERMISSIONS + the role_permissions table + hasPerm()),
 * so the server agrees with what each person already sees on screen.
 *
 * Team (non-admin):
 *   - never sees other people's passwords; salaries only with a salary permission
 *   - money tables (payroll, expenses, payments, outstanding…) only with a finance permission
 *   - cannot change roles, salaries, passwords or employment terms of others
 *     without the matching HR permission; app_settings only for their points request
 * Client:
 *   - only rows that belong to their own company; never team salaries/passwords
 *   - writes only on their own company's records
 */

const RP_DEFAULTS = [
    'hr' => ['hr.view_team','hr.edit_team','hr.manage_roles','hr.view_salary','hr.edit_salary','hr.view_performance','hr.approve_leave','hr.upload_attendance','hr.manage_recruitment'],
    'account_manager' => ['hr.view_performance','clients.manage','assets.manage','crm.leads','finance.quotes'],
    'account_director' => ['hr.view_performance','clients.manage','assets.manage','crm.leads','finance.quotes','hr.manage_recruitment'],
    'account_executive' => ['hr.view_performance','clients.manage','assets.manage','crm.leads','finance.quotes'],
    'content_creator' => ['assets.manage'], 'graphic_designer' => ['assets.manage'], 'senior_graphic_designer' => ['assets.manage'],
    'video_editor' => ['assets.manage'], 'senior_video_editor' => ['assets.manage'],
    'accountant' => ['finance.quotes','finance.full'],
];

const RP_SALARY_COLS = ['salary','probation_salary','bank_account','bank_name','iban','national_id','national_id_number'];
const RP_SECRET_TEAM_COLS = ['password','password_hash'];
const RP_PROTECTED_TEAM_COLS = ['role','status','email','password','salary','probation_salary','probation_months','manager_id',
    'employment_type','attendance_policy_exempt','termination_date','termination_reason','start_date'];

/** Same rule as hasPerm() in app.jsx. */
function rpHas(PDO $pdo, array $s, $key) {
    $role = (string)$s['role'];
    if ($role === 'admin') return true;
    static $cache = [];
    if (!isset($cache[$role])) {
        $list = null;
        try {
            $st = $pdo->prepare("SELECT permission_key, allowed FROM role_permissions WHERE role = ?");
            $st->execute([$role]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $allowed = array_values(array_map(fn($r) => $r['permission_key'], array_filter($rows, fn($r) => !empty($r['allowed']))));
            if ($allowed) $list = $allowed; // app: rolePerms[role] exists only when it has allowed rows
        } catch (Throwable $e) {}
        $cache[$role] = $list ?? (RP_DEFAULTS[$role] ?? []);
    }
    return in_array($key, $cache[$role], true);
}

function rpIsAdmin(array $s) { return ($s['role'] ?? '') === 'admin'; }
function rpCanSalary(PDO $pdo, array $s) { return rpHas($pdo, $s, 'hr.view_salary') || rpHas($pdo, $s, 'hr.edit_salary'); }
function rpCanFinance(PDO $pdo, array $s) { return rpHas($pdo, $s, 'finance.full'); }

function rpColumns(PDO $pdo, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cols = [];
    try {
        $st = $pdo->query("SELECT * FROM `$table` LIMIT 0");
        for ($i = 0; $i < $st->columnCount(); $i++) { $m = $st->getColumnMeta($i); if ($m && isset($m['name'])) $cols[] = $m['name']; }
    } catch (Throwable $e) {}
    return $cache[$table] = $cols;
}

function rpDeny($msg = "You don't have permission to do that") {
    http_response_code(403);
    echo json_encode(['error' => $msg]);
    exit;
}

/* ─────────────────────────────── TEAM ─────────────────────────────── */

/** Tables a team member without the right permission gets an empty list for. */
function rpTeamTableAllowed(PDO $pdo, array $s, $table) {
    if (rpIsAdmin($s)) return true;
    switch ($table) {
        case 'payroll_runs': case 'expenses':
            return rpCanFinance($pdo, $s) || rpCanSalary($pdo, $s);
        case 'outstanding_liabilities': case 'outstanding_payments': case 'finance_client_notes':
            return rpCanFinance($pdo, $s);
        case 'payments': case 'subscription_payments':
            return rpCanFinance($pdo, $s) || rpHas($pdo, $s, 'clients.manage');
        case 'auth_sessions': case 'auth_attempts':
            return false;
    }
    return true;
}

function rpTeamFilterRows(PDO $pdo, array $s, $table, array $rows) {
    if (rpIsAdmin($s)) return $rows;
    if (!rpTeamTableAllowed($pdo, $s, $table)) {
        // Own payroll rows stay visible (a person's own pay is theirs to see).
        if ($table === 'payroll_runs') return array_values(array_filter($rows, fn($r) => (string)($r['team_member_id'] ?? '') === (string)$s['user_id']));
        return [];
    }
    $me = (string)$s['user_id'];
    if ($table === 'team_members') {
        $sal = rpCanSalary($pdo, $s);
        foreach ($rows as &$r) {
            if ((string)($r['id'] ?? '') === $me) { unset($r['password_hash']); continue; }
            foreach (RP_SECRET_TEAM_COLS as $c) unset($r[$c]);
            if (!$sal) foreach (RP_SALARY_COLS as $c) unset($r[$c]);
        }
        unset($r);
    }
    if ($table === 'clients' && !rpHas($pdo, $s, 'clients.manage')) foreach ($rows as &$r) { unset($r['portal_password']); } unset($r);
    if ($table === 'client_users' && !rpHas($pdo, $s, 'clients.manage')) foreach ($rows as &$r) { unset($r['password'], $r['password_hash']); } unset($r);
    return $rows;
}

/** Returns the record to write (protected fields removed), or denies. */
function rpTeamCheckWrite(PDO $pdo, array $s, $table, $method, $id, array $rec) {
    if (rpIsAdmin($s)) return $rec;
    $me = (string)$s['user_id'];
    if (!rpTeamTableAllowed($pdo, $s, $table)) rpDeny();
    if ($table === 'role_permissions' && !rpHas($pdo, $s, 'hr.manage_roles')) rpDeny();
    if ($table === 'app_settings') {
        // Staff only ever save their own prize request here — nothing else in the settings row.
        if ($method !== 'PATCH') rpDeny();
        return array_intersect_key($rec, ['points_system' => 1]);
    }
    if ($table === 'team_members') {
        if ($method === 'DELETE' || ($method === 'POST' && !rpHas($pdo, $s, 'hr.edit_team'))) rpDeny();
        $own = (string)$id === $me;
        $canTeam = rpHas($pdo, $s, 'hr.edit_team');
        $canSal = rpHas($pdo, $s, 'hr.edit_salary');
        foreach (RP_PROTECTED_TEAM_COLS as $c) {
            if (!array_key_exists($c, $rec)) continue;
            if ($c === 'password' && $own) continue;                                   // change your own password
            if (in_array($c, ['salary','probation_salary','probation_months'], true) ? $canSal : $canTeam) continue;
            unset($rec[$c]);
        }
        return $rec;
    }
    if ($method === 'DELETE' && in_array($table, ['clients', 'invoices', 'subscriptions', 'quotes', 'client_users'], true) && !rpHas($pdo, $s, 'clients.manage') && !rpCanFinance($pdo, $s)) rpDeny();
    return $rec;
}

/* ────────────────────────────── CLIENT ────────────────────────────── */

function rpClientInfo(PDO $pdo, array $s) {
    static $c = null;
    if ($c !== null) return $c;
    $st = $pdo->prepare("SELECT id, name FROM clients WHERE id = ? LIMIT 1");
    $st->execute([(string)$s['client_id']]);
    return $c = ($st->fetch(PDO::FETCH_ASSOC) ?: ['id' => (string)$s['client_id'], 'name' => '']);
}

/** Extra WHERE (and binds) so a client's query only touches its own company's rows; null = not allowed at all. */
function rpClientWhere(PDO $pdo, array $s, $table, array &$bind) {
    $b = [];
    $w = rpClientWhereInner($pdo, $s, $table, $b);
    if ($w !== null) foreach ($b as $k => $v) if (strpos($w, $k) !== false) $bind[$k] = $v; // only binds the query really uses
    return $w;
}
function rpClientWhereInner(PDO $pdo, array $s, $table, array &$bind) {
    $cl = rpClientInfo($pdo, $s);
    $cols = rpColumns($pdo, $table);
    $bind[':rp_cid'] = $cl['id'];
    if (in_array($table, ['app_settings', 'branding_assets', 'role_permissions'], true)) return '1=1';
    if ($table === 'clients') return '`id` = :rp_cid';
    if ($table === 'team_members') return '1=1'; // names/avatars for display; filtered to safe columns below
    if ($table === 'notifications' || $table === 'notification_prefs') {
        $bind[':rp_me'] = (string)$s['email'];
        return in_array('recipient_email', $cols, true) ? '`recipient_email` = :rp_me' : (in_array('user_email', $cols, true) ? '`user_email` = :rp_me' : null);
    }
    if (in_array('client_id', $cols, true)) {
        if (in_array('client_name', $cols, true) && $cl['name'] !== '') {
            $bind[':rp_cname'] = $cl['name'];
            return "(`client_id` = :rp_cid OR ((`client_id` IS NULL OR `client_id` = '') AND `client_name` = :rp_cname))";
        }
        return '`client_id` = :rp_cid';
    }
    if (in_array('post_id', $cols, true)) return "`post_id` IN (SELECT id FROM posts WHERE client_id = :rp_cid)";
    if (in_array('contact_report_id', $cols, true)) return "`contact_report_id` IN (SELECT id FROM contact_reports WHERE client_id = :rp_cid)";
    return null;
}

function rpClientFilterRows(array $rows, $table) {
    foreach ($rows as &$r) {
        if ($table === 'team_members') { $r = array_intersect_key($r, array_flip(['id','name','email','role','title','avatar_url','status'])); continue; }
        $re = in_array($table, ['app_settings', 'branding_assets'], true) ? '/key|token|secret|password|smtp|credential|webhook/i' : '/password|token|secret|api_key|smtp|salary/i';
        foreach (array_keys($r) as $k) if (preg_match($re, $k)) unset($r[$k]);
    }
    unset($r);
    return $rows;
}

/** A client may only write records of its own company. */
function rpClientCheckWrite(PDO $pdo, array $s, $table, $method, $id, array $rec) {
    if (in_array($table, ['team_members', 'role_permissions', 'app_settings', 'payroll_runs', 'expenses', 'payments', 'subscription_payments',
        'outstanding_liabilities', 'outstanding_payments', 'finance_client_notes', 'clients', 'auth_sessions', 'auth_attempts'], true)) rpDeny();
    if (in_array($table, ['notifications', 'activity_logs', 'email_logs'], true) && $method === 'POST') return $rec;
    $cl = rpClientInfo($pdo, $s);
    $bind = [];
    $where = rpClientWhere($pdo, $s, $table, $bind);
    if ($where === null) rpDeny();
    $cols = rpColumns($pdo, $table);
    if ($method === 'POST') {
        if (in_array('client_id', $cols, true)) { $rec['client_id'] = $cl['id']; if (in_array('client_name', $cols, true) && empty($rec['client_name'])) $rec['client_name'] = $cl['name']; }
        elseif (in_array('post_id', $cols, true)) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE id = ? AND client_id = ?");
            $st->execute([(string)($rec['post_id'] ?? ''), $cl['id']]);
            if (!(int)$st->fetchColumn()) rpDeny();
        } elseif (in_array('contact_report_id', $cols, true)) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM contact_reports WHERE id = ? AND client_id = ?");
            $st->execute([(string)($rec['contact_report_id'] ?? ''), $cl['id']]);
            if (!(int)$st->fetchColumn()) rpDeny();
        }
        return $rec;
    }
    // PATCH / DELETE: the existing row must be theirs.
    $bind[':rp_id'] = $id;
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `id` = :rp_id AND $where");
    $st->execute($bind);
    if (!(int)$st->fetchColumn()) rpDeny();
    unset($rec['client_id'], $rec['client_name']);
    if ($table === 'client_users') unset($rec['role'], $rec['status']);
    return $rec;
}
