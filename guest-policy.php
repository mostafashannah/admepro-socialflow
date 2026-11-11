<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * guest-policy.php — what a "guest pass" may do (security stage 2b-2).
 *
 * Public pages (careers form, interview / offer / task / onboarding /
 * complete-application / policy / contract / invitation links) get a guest
 * session from auth.php?action=guest. That session only opens the ONE record
 * its link points to, and only lets the page change the fields it really
 * changes. Everything else is refused.
 *
 * Kinds and the record they open (user_id of the guest session):
 *   careers    — none at first; becomes the application it creates
 *   interview / offer / task / onboarding / complete — a job_applications row
 *   policy / contract — a team_members row
 *   invite     — a user_invitations row
 */

const GUEST_KINDS = [
    'interview'  => ['job_applications', 'interview_scheduling_token'],
    'offer'      => ['job_applications', 'offer_token'],
    'task'       => ['job_applications', 'task_token'],
    'onboarding' => ['job_applications', 'onboarding_token'],
    'complete'   => ['job_applications', 'completion_token'],
    'policy'     => ['team_members', 'policy_token'],
    'contract'   => ['team_members', 'contract_sign_token'],
    'invite'     => ['user_invitations', 'token'],
];

// Fields each kind may change on its own job application.
const GUEST_APP_FIELDS = [
    'careers'    => ['ai_score', 'ai_summary', 'ai_extracted', 'ai_review_status', 'job_opening_id', 'job_title'],
    'interview'  => ['interview_candidate_note', 'interview_selected_slot', 'interview_confirmed_slot', 'status'],
    'offer'      => ['offer_candidate_response', 'offer_negotiation_letter', 'offer_responded_at', 'status'],
    'task'       => ['task_submission_url', 'task_submission_link', 'task_submission_note', 'task_submitted_at', 'task_token'],
    'complete'   => ['completion_token', 'candidate_phone', 'candidate_name', 'expected_salary', 'available_start_date', 'open_to_task',
                     'linkedin_url', 'behance_url', 'canva_url', 'instagram_url', 'video_url', 'portfolio_attachment_url', 'cv_url',
                     'ai_review_status', 'ai_score', 'ai_summary', 'ai_extracted'],
    'onboarding' => ['onboarding_id_front_url', 'onboarding_id_back_url', 'onboarding_photo_url', 'onboarding_fish_url', 'onboarding_certificate_url',
                     'onboarding_social_insurance_no', 'onboarding_accepted_at', 'onboarding_completed_at'],
];
const GUEST_STATUS_VALUES = ['interview' => ['interview'], 'offer' => ['hired', 'rejected_after_offer']];

// What the careers form may put on a NEW application.
const GUEST_NEW_APP_FIELDS = ['job_opening_id', 'job_title', 'candidate_name', 'candidate_email', 'candidate_phone', 'cv_url', 'cover_letter',
    'portfolio_url', 'portfolio_attachment_url', 'linkedin_url', 'instagram_url', 'video_url', 'current_salary', 'expected_salary',
    'available_start_date', 'open_to_task', 'ai_review_status', 'ai_score', 'ai_summary'];

// Columns a guest may see when it reads the team list (to notify admins).
const GUEST_TEAM_COLUMNS = ['id', 'name', 'email', 'role', 'status'];

function guestDeny($msg = 'Not allowed for this link') {
    http_response_code(403);
    echo json_encode(['error' => $msg]);
    exit;
}

/** Rows of app_settings / branding_assets with anything secret-looking removed. */
function guestSafeSettingsRow(array $row) {
    foreach (array_keys($row) as $k) {
        if (preg_match('/key|token|secret|password|smtp|credential|webhook/i', $k)) unset($row[$k]);
    }
    return $row;
}

function guestOnly(array $row, array $cols) { return array_intersect_key($row, array_flip($cols)); }

/** A candidate never sees the team's internal assessment of them. */
function guestHideInternal(array $row) {
    foreach (array_keys($row) as $k) {
        if (preg_match('/^(notes|offer_notes|ai_|portfolio_score|interview_(experience|dress|creativity|communication)_score|rating|score|feedback|internal|hr_|recruiter|admin_|source|email_message_id|interview_slot_history)/', $k)) unset($row[$k]);
    }
    return $row;
}

function guestFetchOne(PDO $pdo, $table, $id) {
    if (!$id) return null;
    $st = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Filters rows a guest asked for (GET). Returns the rows it may see.
 * Called with rows already fetched by api.php's normal query.
 */
function guestFilterRows(PDO $pdo, array $s, $table, array $rows, array $query) {
    $kind = $s['role']; $own = $s['user_id'];
    $pub = ['app_settings', 'branding_assets'];
    if (in_array($table, $pub, true)) return array_map('guestSafeSettingsRow', $rows);
    if ($table === 'role_permissions' || $table === 'notification_prefs') return $rows;
    if ($table === 'team_members') {
        // Own record for policy/contract links (a few more fields), otherwise just who to notify.
        if (in_array($kind, ['policy', 'contract'], true)) {
            $extra = $kind === 'policy' ? ['title', 'policy_accepted_at', 'policy_token'] : ['title', 'signed_contract_url', 'signed_contract_uploaded_at', 'contract_sign_token'];
            $mine = array_values(array_filter($rows, fn($r) => (string)$r['id'] === (string)$own));
            if ($mine) return array_map(fn($r) => guestOnly($r, array_merge(GUEST_TEAM_COLUMNS, $extra)), $mine);
        }
        return array_map(fn($r) => guestOnly($r, GUEST_TEAM_COLUMNS), $rows);
    }
    if ($table === 'job_openings') {
        if ($kind === 'careers') return array_values(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'open'));
        if ($kind === 'complete') {
            $app = guestFetchOne($pdo, 'job_applications', $own);
            return array_values(array_filter($rows, fn($r) => $app && (string)$r['id'] === (string)$app['job_opening_id']));
        }
        return [];
    }
    if ($table === 'job_applications') {
        if ($kind === 'careers') {
            // Duplicate check only: needs both filters, and only learns the ids.
            if (empty($query['candidate_email']) || empty($query['job_opening_id'])) return [];
            return array_map(fn($r) => ['id' => $r['id']], $rows);
        }
        if ($kind === 'invite') {
            $inv = guestFetchOne($pdo, 'user_invitations', $own);
            $src = $inv['source_application_id'] ?? null;
            return array_map('guestHideInternal', array_values(array_filter($rows, fn($r) => $src && (string)$r['id'] === (string)$src)));
        }
        return array_map('guestHideInternal', array_values(array_filter($rows, fn($r) => (string)$r['id'] === (string)$own)));
    }
    if ($table === 'user_invitations' && $kind === 'invite') {
        return array_values(array_filter($rows, fn($r) => (string)$r['id'] === (string)$own));
    }
    if ($table === 'client_users' && $kind === 'invite') {
        return array_map(fn($r) => ['id' => $r['id']], $rows); // "already accepted?" check only
    }
    return [];
}

/** Checks / rewrites a guest write. Returns the (possibly rewritten) record, or denies. */
function guestCheckWrite(PDO $pdo, array $s, $table, $method, $id, array $rec) {
    $kind = $s['role']; $own = $s['user_id'];
    // Small, low-risk logs every public page writes.
    if ($method === 'POST' && in_array($table, ['notifications', 'email_logs', 'activity_logs'], true)) return $rec;
    if ($method === 'POST' && $table === 'job_application_activity') {
        if ((string)($rec['application_id'] ?? '') === '' || (string)$rec['application_id'] !== (string)$own) guestDeny();
        return $rec;
    }
    if ($table === 'job_applications') {
        if ($method === 'POST') {
            if ($kind !== 'careers' || $own) guestDeny('Only one application per visit');
            $rec = guestOnly($rec, GUEST_NEW_APP_FIELDS);
            $rec['status'] = 'new';
            if (isset($rec['ai_review_status']) && !in_array($rec['ai_review_status'], ['pending', 'cv_error', 'no_cv'], true)) $rec['ai_review_status'] = 'pending';
            if (isset($rec['ai_score'])) $rec['ai_score'] = 0;
            return $rec;
        }
        if ($method === 'PATCH') {
            $allowedId = $own;
            $fields = GUEST_APP_FIELDS[$kind] ?? null;
            if ($kind === 'invite') {
                $inv = guestFetchOne($pdo, 'user_invitations', $own);
                $allowedId = $inv['source_application_id'] ?? null;
                $fields = ['linked_team_member_id'];
            }
            if (!$fields || !$allowedId || (string)$id !== (string)$allowedId) guestDeny();
            $rec = guestOnly($rec, $fields);
            if (isset($rec['status']) && !in_array($rec['status'], GUEST_STATUS_VALUES[$kind] ?? [], true)) unset($rec['status']);
            foreach (['task_token', 'completion_token'] as $t) if (array_key_exists($t, $rec)) $rec[$t] = null; // may only clear its own link
            return $rec;
        }
        guestDeny();
    }
    if ($table === 'team_members') {
        if ($method === 'PATCH') {
            if ($kind === 'policy' && (string)$id === (string)$own) return guestOnly($rec, ['policy_accepted_at']);
            if ($kind === 'contract' && (string)$id === (string)$own) return guestOnly($rec, ['signed_contract_url', 'signed_contract_uploaded_at']);
            if ($kind === 'onboarding') {
                $app = guestFetchOne($pdo, 'job_applications', $own);
                if ($app && !empty($app['linked_team_member_id']) && (string)$id === (string)$app['linked_team_member_id'])
                    return guestOnly($rec, ['avatar_url', 'id_photo_front_url', 'id_photo_back_url']);
            }
            guestDeny();
        }
        if ($method === 'POST' && $kind === 'invite') {
            $inv = guestFetchOne($pdo, 'user_invitations', $own);
            if (!$inv || ($inv['user_type'] ?? '') === 'client' || ($inv['status'] ?? '') === 'accepted') guestDeny('This invitation was already used');
            $ex = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE email = ?");
            $ex->execute([$inv['email']]);
            if ((int)$ex->fetchColumn() > 0) guestDeny('This account already exists');
            // Only what the person chooses themselves comes from the browser; everything the admin set
            // (role, salary, manager, leave, start date…) is taken from the invitation itself.
            $out = guestOnly($rec, ['name', 'avatar_url', 'password', 'whatsapp_number', 'id_photo_front_url', 'id_photo_back_url']);
            foreach (['email', 'role', 'title', 'manager_id', 'salary', 'probation_salary', 'probation_months', 'vacation_days_total',
                      'wfh_days_total', 'personal_leave_hours_total', 'source_application_id', 'start_date'] as $k) {
                if (array_key_exists($k, $inv)) $out[$k] = $inv[$k];
            }
            if (!empty($inv['whatsapp_number'])) $out['whatsapp_number'] = $inv['whatsapp_number'];
            if (!empty($inv['name'])) $out['name'] = $inv['name'];
            $future = !empty($inv['start_date']) && substr($inv['start_date'], 0, 10) > gmdate('Y-m-d');
            $out['status'] = $future ? 'inactive' : 'active';
            $out['pending_start'] = $future ? 1 : 0;
            if (isset($rec['id'])) $out['id'] = $rec['id'];
            return $out;
        }
        guestDeny();
    }
    if ($table === 'client_users' && $method === 'POST' && $kind === 'invite') {
        $inv = guestFetchOne($pdo, 'user_invitations', $own);
        if (!$inv || ($inv['user_type'] ?? '') !== 'client' || ($inv['status'] ?? '') === 'accepted') guestDeny('This invitation was already used');
        $out = guestOnly($rec, ['name', 'avatar_url', 'password', 'mobile']);
        $out['email'] = $inv['email'];
        $out['role'] = $inv['role'] ?? 'client_member';
        $out['client_id'] = $inv['client_id'] ?? '';
        $out['client_name'] = $inv['client_name'] ?? '';
        $out['status'] = 'active';
        if (isset($rec['id'])) $out['id'] = $rec['id'];
        return $out;
    }
    if ($table === 'user_invitations' && $method === 'PATCH' && $kind === 'invite' && (string)$id === (string)$own) {
        $out = guestOnly($rec, ['status']);
        if (($out['status'] ?? '') !== 'accepted') guestDeny();
        return $out;
    }
    guestDeny();
}

/** Upload folders a guest may write to. */
function guestUploadAllowed(array $s, $path) {
    $kind = $s['role'];
    if (in_array($kind, ['careers', 'complete', 'task', 'onboarding'], true)) return strpos($path, 'job-applications/') === 0;
    if ($kind === 'contract') return strpos($path, 'team-members/signed-contracts/') === 0;
    if ($kind === 'invite') return strpos($path, 'avatars/') === 0 || strpos($path, 'uploads/') === 0 || strpos($path, 'team-members/') === 0;
    return false;
}

/** Simple per-guest-pass counter for things that cost money or reach people (AI, email, WhatsApp). */
function guestQuota(PDO $pdo, array $s, $bucket, $max) {
    authEnsureSchema($pdo);
    $key = 'guest:' . $bucket . ':' . $s['token_hash'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE email = ?");
    $st->execute([$key]);
    if ((int)$st->fetchColumn() >= $max) return false;
    $pdo->prepare("INSERT INTO auth_attempts (id, email, ip, ok, at) VALUES (?, ?, ?, 1, ?)")
        ->execute([bin2hex(random_bytes(16)), $key, authClientIp(), authNow()]);
    return true;
}
