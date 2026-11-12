<?php
/**
 * auth-lib.php — server-side login sessions (stage 1 of the security work).
 *
 * Until now the only thing protecting the API was one shared key that ships
 * inside app.js. This adds real per-person sessions next to it:
 *   - auth.php logs a person in (password checked ON THE SERVER) and hands
 *     back a random session token; only its SHA-256 is stored.
 *   - authResolve() recognises either that session token or the legacy
 *     shared key, so nothing that works today stops working.
 *
 * Stage 1 changes no permissions: a session is recorded and recognised, the
 * legacy key still grants what it always did. Later stages enforce per-role
 * rules for sessions and then switch the shared key off.
 */

const AUTH_SESSION_DAYS = 30;        // sliding expiry
const AUTH_MAX_FAILS = 10;           // failed logins per email or IP ...
const AUTH_FAIL_WINDOW_MIN = 15;     // ... inside this many minutes

function authPdo() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    // DB_DSN is only ever defined by the local test harness (SQLite).
    $pdo = defined('DB_DSN')
        ? new PDO(DB_DSN, null, null, $opts)
        : new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
    return $pdo;
}

function authNow($offsetSeconds = 0) { return gmdate('Y-m-d H:i:s', time() + $offsetSeconds); }

// Creates the two small tables this needs the first time they are used, so
// deploying is just "git pull" — no manual migration step to forget.
// The app's DB user may not be allowed to CREATE tables (they come from migration-auth-tables.sql);
// if the table already exists that's fine, so a refused CREATE is ignored.
function sfTryExec(PDO $pdo, $sql) { try { $pdo->exec($sql); } catch (Throwable $e) { /* table made by the migration */ } }

function authEnsureSchema(PDO $pdo) {
    static $done = false;
    if ($done) return;
    // Fast path: the tables already exist (made by the migration) — a cheap read, no DDL on every request.
    try { $pdo->query("SELECT 1 FROM auth_sessions LIMIT 0"); $pdo->query("SELECT 1 FROM auth_attempts LIMIT 0"); $done = true; return; } catch (Throwable $e) {}
    sfTryExec($pdo, "CREATE TABLE IF NOT EXISTS auth_sessions (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        user_type VARCHAR(20) NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        email VARCHAR(255) NULL,
        role VARCHAR(64) NULL,
        client_id VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        revoked TINYINT NOT NULL DEFAULT 0
    )");
    sfTryExec($pdo, "CREATE TABLE IF NOT EXISTS auth_attempts (
        id VARCHAR(40) NOT NULL PRIMARY KEY,
        email VARCHAR(255) NULL,
        ip VARCHAR(64) NULL,
        ok TINYINT NOT NULL DEFAULT 0,
        at DATETIME NOT NULL
    )");
    $done = true;
}

function authClientIp() {
    $xf = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xf) { $first = trim(explode(',', $xf)[0]); if (filter_var($first, FILTER_VALIDATE_IP)) return $first; }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Pulls the credential the request carries: [sessionToken|null, key|null]. */
function authCredentials() {
    $session = $_SERVER['HTTP_X_SF_SESSION'] ?? '';
    $key = $_SERVER['HTTP_APIKEY'] ?? '';
    $bearer = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) $bearer = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
    if (!$key && $bearer) $key = $bearer;
    // A Bearer value that isn't the shared key may itself be a session token.
    if (!$session && $bearer && !hash_equals(API_KEY, (string)$bearer)) $session = $bearer;
    return [$session ?: null, $key ?: null];
}

/** Looks a session token up. Returns the session row (array) or null. */
function authFindSession($token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    try {
        $pdo = authPdo();
        authEnsureSchema($pdo);
        $st = $pdo->prepare("SELECT * FROM auth_sessions WHERE token_hash = ? AND revoked = 0 AND expires_at > ?");
        $st->execute([hash('sha256', $token), authNow()]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return null;
        // Use the person's CURRENT role and status, not what they had at login: a role change applies
        // straight away, and a blocked/deactivated person loses access immediately.
        if ($s['user_type'] === 'team') {
            $u = $pdo->prepare("SELECT role, status FROM team_members WHERE id = ? LIMIT 1");
            $u->execute([$s['user_id']]);
            $cur = $u->fetch(PDO::FETCH_ASSOC);
            if (!$cur || in_array($cur['status'], ['blocked', 'inactive'], true)) return null;
            $s['role'] = $cur['role'];
        } elseif ($s['user_type'] === 'client' && $s['user_id'] !== $s['client_id']) {
            $u = $pdo->prepare("SELECT status FROM client_users WHERE id = ? LIMIT 1");
            $u->execute([$s['user_id']]);
            $cur = $u->fetch(PDO::FETCH_ASSOC);
            if ($cur && $cur['status'] === 'blocked') return null;
        }
        // Slide the expiry / last-seen at most once every 10 minutes.
        if (($s['user_type'] ?? '') !== 'guest' && strtotime($s['last_seen_at'] . ' UTC') < time() - 600) {
            $pdo->prepare("UPDATE auth_sessions SET last_seen_at = ?, expires_at = ? WHERE token_hash = ?")
                ->execute([authNow(), authNow(AUTH_SESSION_DAYS * 86400), $s['token_hash']]);
        }
        return $s;
    } catch (Throwable $e) {
        error_log('[auth] session lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Who is calling? Returns
 *   ['kind' => 'session', 'session' => row]  — a logged-in person
 *   ['kind' => 'key']                        — the legacy shared key
 *   null                                     — neither
 * A valid session wins when both are sent.
 */
function authResolve() {
    [$session, $key] = authCredentials();
    if ($session) {
        $s = authFindSession($session);
        if ($s) return ['kind' => 'session', 'session' => $s];
    }
    // The shared key used to ship inside app.js, so it is public. It is switched OFF unless config.php
    // sets ALLOW_LEGACY_KEY to true (emergency rollback only).
    if ($key && defined('ALLOW_LEGACY_KEY') && ALLOW_LEGACY_KEY && hash_equals(API_KEY, (string)$key)) return ['kind' => 'key'];
    return null;
}

function authCreateSession(PDO $pdo, $userType, array $user, $role, $clientId = null) {
    authEnsureSchema($pdo);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_sessions (token_hash, user_type, user_id, email, role, client_id, created_at, last_seen_at, expires_at, ip, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([hash('sha256', $token), $userType, (string)$user['id'], $user['email'] ?? null, $role, $clientId,
            authNow(), authNow(), authNow(AUTH_SESSION_DAYS * 86400), authClientIp(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    return $token;
}

// Sign a person out everywhere (e.g. after their password changes). $keepToken stays signed in.
function authRevokeUserSessions(PDO $pdo, $userType, $userId, $keepToken = null) {
    try {
        $keep = $keepToken ? hash('sha256', $keepToken) : '';
        $pdo->prepare("UPDATE auth_sessions SET revoked = 1 WHERE user_type = ? AND user_id = ? AND token_hash <> ?")->execute([$userType, (string)$userId, $keep]);
        $pdo->prepare("DELETE FROM auth_devices WHERE user_type = ? AND user_id = ?")->execute([$userType, (string)$userId]);
    } catch (Throwable $e) { /* best-effort */ }
}

function authRevokeSession($token) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return;
    try {
        $pdo = authPdo();
        authEnsureSchema($pdo);
        $pdo->prepare("UPDATE auth_sessions SET revoked = 1 WHERE token_hash = ?")->execute([hash('sha256', $token)]);
    } catch (Throwable $e) { /* nothing to revoke */ }
}

function authTooManyFails(PDO $pdo, $email) {
    authEnsureSchema($pdo);
    $since = authNow(-AUTH_FAIL_WINDOW_MIN * 60);
    $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ok = 0 AND at > ? AND email = ?");
    $st->execute([$since, strtolower((string)$email)]);
    if ((int)$st->fetchColumn() >= AUTH_MAX_FAILS) return true;
    // Per-address limit is looser: a whole office usually shares one IP.
    $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ok = 0 AND at > ? AND ip = ?");
    $st->execute([$since, authClientIp()]);
    return (int)$st->fetchColumn() >= AUTH_MAX_FAILS * 4;
}

function authLogAttempt(PDO $pdo, $email, $ok) {
    try {
        authEnsureSchema($pdo);
        $pdo->prepare("INSERT INTO auth_attempts (id, email, ip, ok, at) VALUES (?, ?, ?, ?, ?)")
            ->execute([bin2hex(random_bytes(16)), strtolower((string)$email), authClientIp(), $ok ? 1 : 0, authNow()]);
        // Keep the table small.
        if (random_int(1, 50) === 1) $pdo->prepare("DELETE FROM auth_attempts WHERE at < ?")->execute([authNow(-7 * 86400)]);
    } catch (Throwable $e) { /* best-effort */ }
}

/**
 * Checks a typed password against what is on file. Prefers the scrambled
 * password_hash; falls back to the legacy readable `password` column.
 * Returns true/false, or null when the account has no password at all.
 */
function authPasswordOk(array $row, $typed, $legacyField = 'password') {
    $hash = $row['password_hash'] ?? '';
    $plain = (string)($row[$legacyField] ?? '');
    if ($hash) {
        if (password_verify((string)$typed, $hash)) return true;
        return $plain !== '' && hash_equals($plain, (string)$typed); // an older readable one not converted yet
    }
    if ($plain === '') return null;
    return hash_equals($plain, (string)$typed);
}

/** Saves a scrambled copy of the password (adds the column the first time). */
function authStoreHash(PDO $pdo, $table, $id, $typed) {
    if (!in_array($table, ['team_members', 'client_users'], true) || (string)$typed === '') return;
    $hash = password_hash((string)$typed, PASSWORD_DEFAULT);
    $sql = "UPDATE {$table} SET password_hash = ?, password = '' WHERE id = ?"; // the readable copy is cleared
    try {
        $pdo->prepare($sql)->execute([$hash, $id]);
    } catch (Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN password_hash VARCHAR(255) NULL");
            $pdo->prepare($sql)->execute([$hash, $id]);
        } catch (Throwable $e2) { error_log('[auth] could not store hash: ' . $e2->getMessage()); }
    }
}

/** Sends an email through Resend (same account mail.php uses). Returns true on success. */
function authSendMail($to, $subject, $html, $fromName = 'SocialFlow') {
    if (!defined('RESEND_API_KEY') || !RESEND_API_KEY) return false;
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => json_encode(['from' => "$fromName <noreply@admepro.com>", 'to' => is_array($to) ? $to : [$to], 'subject' => $subject, 'html' => $html]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * For endpoints only the team uses (publishing, WhatsApp, attendance import…):
 * a team login session, or the legacy key while it is still enabled.
 * $allowClient lets a client portal session through as well.
 */
function authTeamOk($allowClient = false) {
    $a = authResolve();
    if (!$a) return false;
    if ($a['kind'] === 'key') return true;
    $t = $a['session']['user_type'] ?? '';
    return $t === 'team' || ($allowClient && $t === 'client');
}

/* ───────────── Email sign-in code (two-step) ─────────────
 * Required for the roles that see money and salaries. A device can be
 * remembered for 30 days so the code is not asked every time. */
const AUTH_2FA_ROLES = ['admin', 'accountant', 'account_director'];

function auth2faSchema(PDO $pdo) {
    sfTryExec($pdo, "CREATE TABLE IF NOT EXISTS auth_codes (
        id CHAR(32) NOT NULL PRIMARY KEY, user_type VARCHAR(20) NOT NULL, user_id VARCHAR(64) NOT NULL,
        code_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, attempts INT NOT NULL DEFAULT 0, used TINYINT NOT NULL DEFAULT 0)");
    sfTryExec($pdo, "CREATE TABLE IF NOT EXISTS auth_devices (
        token_hash CHAR(64) NOT NULL PRIMARY KEY, user_type VARCHAR(20) NOT NULL, user_id VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL)");
}

function authDeviceTrusted(PDO $pdo, $userType, $userId, $deviceToken) {
    if (!is_string($deviceToken) || !preg_match('/^[a-f0-9]{64}$/', $deviceToken)) return false;
    auth2faSchema($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM auth_devices WHERE token_hash = ? AND user_type = ? AND user_id = ? AND expires_at > ?");
    $st->execute([hash('sha256', $deviceToken), $userType, (string)$userId, authNow()]);
    return (int)$st->fetchColumn() > 0;
}

/** Emails a 6-digit code and returns the challenge id. */
function authStartChallenge(PDO $pdo, $userType, array $user) {
    auth2faSchema($pdo);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $id = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO auth_codes (id, user_type, user_id, code_hash, expires_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([$id, $userType, (string)$user['id'], hash('sha256', $id . $code), authNow(600)]);
    $name = htmlspecialchars(explode(' ', trim((string)($user['name'] ?? '')))[0] ?: 'there');
    $html = '<div style="font-family:Montserrat,Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px;background:#fff;border-radius:12px;text-align:center">'
        . '<h2 style="font-size:20px;font-weight:800;color:#111827;margin:0 0 8px">Your sign-in code</h2>'
        . '<p style="color:#4b5563;font-size:14px;line-height:1.6;margin:0">Hi ' . $name . ', use this code to finish signing in to SocialFlow:</p>'
        . '<div style="margin:22px auto;font-size:32px;font-weight:800;letter-spacing:8px;color:#d90b2c;font-family:monospace">' . $code . '</div>'
        . '<p style="color:#6b7280;font-size:12px;line-height:1.6;margin:0">It expires in 10 minutes. If you didn\'t try to sign in, change your password — someone knows it.</p></div>';
    authSendMail($user['email'], 'SocialFlow sign-in code: ' . $code, $html);
    return $id;
}

/** Checks a code. Returns [userType, userId] or an error string. */
function authCheckChallenge(PDO $pdo, $id, $code) {
    auth2faSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM auth_codes WHERE id = ? LIMIT 1");
    $st->execute([(string)$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['used'] || $c['expires_at'] < authNow()) return 'This code has expired. Sign in again to get a new one.';
    if ((int)$c['attempts'] >= 5) return 'Too many wrong codes. Sign in again to get a new one.';
    $pdo->prepare("UPDATE auth_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$id]);
    if (!hash_equals($c['code_hash'], hash('sha256', $id . preg_replace('/\D/', '', (string)$code)))) return 'Wrong code. Check the email and try again.';
    $pdo->prepare("UPDATE auth_codes SET used = 1 WHERE id = ?")->execute([$id]);
    return [$c['user_type'], $c['user_id']];
}

function authRememberDevice(PDO $pdo, $userType, $userId) {
    auth2faSchema($pdo);
    $t = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_devices (token_hash, user_type, user_id, expires_at) VALUES (?, ?, ?, ?)")
        ->execute([hash('sha256', $t), $userType, (string)$userId, authNow(30 * 86400)]);
    return $t;
}
