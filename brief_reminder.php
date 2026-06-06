<?php
/**
 * brief_reminder.php — Daily cron script for Monthly Brief reminders
 *
 * Setup on Hostinger:
 *   Cron command: php /home/u123456789/domains/socialflow.admepro.com/public_html/brief_reminder.php
 *   Schedule: Daily at 9:00 AM (0 9 * * *)
 *
 * This script:
 *   1. Fetches all pending MonthlyBriefs from base44
 *   2. Sends a reminder email to each client
 *   3. Updates reminder_count and last_reminder_at
 */

require_once __DIR__ . '/ai-config.php'; // has B44_API_KEY, B44_APP_ID, MAIL_FROM etc.

// ── Config ─────────────────────────────────────────────────────
define('B44_API', 'https://api.base44.com/api/apps/' . (defined('B44_APP_ID') ? B44_APP_ID : '') . '/entities/');
define('PORTAL_URL', 'https://socialflow.admepro.com');
define('AGENCY_EMAIL', defined('MAIL_FROM') ? MAIL_FROM : 'mostafashannah@gmail.com');

// ── base44 helpers ─────────────────────────────────────────────
function b44_request($method, $path, $body = null) {
    $ch = curl_init(B44_API . $path);
    $headers = ['Content-Type: application/json', 'x-api-key: ' . (defined('B44_API_KEY') ? B44_API_KEY : '')];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function b44_query($table, $filters = [], $sort = '-created_at', $limit = 500) {
    $qs = http_build_query(array_merge(['sort' => $sort, 'limit' => $limit], $filters));
    return b44_request('GET', $table . '?' . $qs);
}

function b44_update($table, $id, $data) {
    return b44_request('PUT', $table . '/' . $id, $data);
}

// ── Email sender ───────────────────────────────────────────────
function send_reminder_email($to, $client_name, $month_label, $reminder_num) {
    require_once __DIR__ . '/mail.php'; // uses PHPMailer or mail()

    $subject = "📋 Reminder #{$reminder_num}: {$month_label} Content Brief — Please Fill In";

    $questions_html = '
        <ol style="padding-left:20px;line-height:2">
          <li><strong>ما المنتجات التي ترغبون بالتركيز عليها هذا الشهر؟</strong><br><em>Which products would you like to focus on this month?</em></li>
          <li><strong>ما القطاعات أو الأسواق المستهدفة لهذا الشهر؟</strong><br><em>What sectors or markets are you targeting this month?</em></li>
          <li><strong>هل توجد مشاريع أو شراكات ترغبون بإبرازها؟</strong><br><em>Are there any projects or partnerships you would like to highlight?</em></li>
          <li><strong>هل يوجد فعاليات خاصة خلال هذا الشهر؟</strong><br><em>Are there any special events taking place?</em></li>
          <li><strong>هل ترغبون بنشر منشورات تقدير أو تكريم خلال هذا الشهر؟</strong><br><em>Would you like to publish any appreciation or recognition posts this month?</em></li>
        </ol>';

    $html = "
    <div style='font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#1a1a2e'>
      <div style='background:#d90b2c;border-radius:12px;padding:20px;text-align:center;margin-bottom:24px'>
        <h2 style='color:#fff;margin:0;font-size:20px'>📋 Monthly Content Brief</h2>
        <p style='color:#ffcccc;margin:8px 0 0;font-size:14px'>{$month_label} · Reminder #{$reminder_num}</p>
      </div>
      <p>Dear <strong>{$client_name}</strong>,</p>
      <p>We're preparing your content plan for <strong>{$month_label}</strong> and we need your input to make it perfect. Please take 3 minutes to answer the following questions in your client portal:</p>
      {$questions_html}
      <div style='text-align:center;margin:28px 0'>
        <a href='" . PORTAL_URL . "' style='display:inline-block;padding:14px 32px;background:#d90b2c;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px'>
          Open Client Portal &rarr;
        </a>
      </div>
      <p style='color:#6b7280;font-size:13px'>This reminder will be sent daily until your brief is submitted. Once submitted, reminders will stop automatically.</p>
      <hr style='border:none;border-top:1px solid #e5e7eb;margin:20px 0'>
      <p style='color:#9ca3af;font-size:12px'>SocialFlow by Admepro &nbsp;|&nbsp; <a href='" . PORTAL_URL . "' style='color:#d90b2c'>Client Portal</a></p>
    </div>";

    // Use the existing mail.php send_mail function if available
    if (function_exists('send_mail')) {
        return send_mail($to, $subject, $html);
    }
    // Fallback: PHP mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: SocialFlow <" . AGENCY_EMAIL . ">\r\n";
    return mail($to, $subject, $html, $headers);
}

// ── Main logic ─────────────────────────────────────────────────
echo "[brief_reminder] Starting at " . date('Y-m-d H:i:s') . "\n";

// Fetch all pending briefs
$result = b44_query('monthly_briefs', ['status' => 'pending']);
$briefs  = $result['entities'] ?? [];

echo "[brief_reminder] Found " . count($briefs) . " pending briefs\n";

$sent = 0;
$skipped = 0;

foreach ($briefs as $brief) {
    $client_email = $brief['client_email'] ?? '';
    $client_name  = $brief['client_name'] ?? 'Valued Client';
    $month_year   = $brief['month_year'] ?? '';
    $reminder_num = ($brief['reminder_count'] ?? 0) + 1;

    // Format month label
    try {
        $dt = new DateTime($month_year . '-01');
        $month_label = $dt->format('F Y');
    } catch (Exception $e) {
        $month_label = $month_year;
    }

    // Skip if no email
    if (empty($client_email)) {
        echo "[brief_reminder] Skipping brief {$brief['id']} — no client email\n";
        $skipped++;
        continue;
    }

    // Send reminder
    $ok = send_reminder_email($client_email, $client_name, $month_label, $reminder_num);

    if ($ok) {
        // Update reminder count
        b44_update('monthly_briefs', $brief['id'], [
            'reminder_count'    => $reminder_num,
            'last_reminder_at'  => date('c'),
            'updated_at'        => date('c'),
        ]);
        echo "[brief_reminder] ✅ Sent reminder #{$reminder_num} to {$client_email} ({$client_name})\n";
        $sent++;
    } else {
        echo "[brief_reminder] ❌ Failed to send to {$client_email}\n";
    }
}

echo "[brief_reminder] Done. Sent: {$sent}, Skipped: {$skipped}\n";
