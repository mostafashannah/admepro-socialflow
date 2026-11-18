<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }
// Shared by sales-agent.php (the screens) and sales-daily-cron.php (the daily machine).
require_once __DIR__ . '/growth-lib.php';

/** HARD RULE: a contact is only kept if it has a valid email address or a mobile/phone number. Cleans both fields. */
function saReachable(array $contacts) {
    $out = [];
    foreach ($contacts as $c) {
        if (!is_array($c)) continue;
        $c['email'] = gEmail($c['email'] ?? '') ?: '';
        $ph = gPhone($c['phone'] ?? ''); $c['phone'] = $ph ?: '';
        if ($c['email'] === '' && $c['phone'] === '') continue;
        $out[] = $c;
    }
    return array_values($out);
}

function saDraftSequence(array $p, $contactIdx, $lang, $angle = '') {
    $c = $p['contacts'][(int)$contactIdx] ?? ($p['contacts'][0] ?? []);
    $L = $lang === 'ar' ? 'Arabic' : 'English';
    $prompt = "Write a 3-email cold outreach sequence in $L from Mostafa Shannah, founder of admepro (creative & digital marketing agency, Cairo — branding, social media, content/video production, websites, ads; https://admepro.com).\n\n"
        . "Prospect: {$p['company']} ({$p['industry']}, {$p['city']} {$p['country']}) — {$p['website']}\nContact: " . ($c['name'] ?? 'unknown') . ', ' . ($c['role'] ?? '') . "\nWhy they fit: {$p['why_fit']}\nSignals: " . implode('; ', (array)$p['signals']) . "\n"
        . ($angle !== '' ? "Angle to use: " . mb_substr($angle, 0, 400) . "\n" : '')
        . "\nRules: short (70–120 words each), specific to their signals, one clear low-friction ask (15-min call or a free quick audit), no fake claims or invented results, human tone, no emojis, plain text. Email 2 sent 3 days later, email 3 a week later as a polite last note. Where it helps, point to one real admepro project or article page on https://admepro.com instead of making claims.\n"
        . "Return ONLY JSON: [{\"step\":1,\"subject\":\"\",\"body\":\"\"},{\"step\":2,…},{\"step\":3,…}]";
    [$ok, $text, , $err] = gClaude(['max_tokens' => 4000, 'output_config' => ['effort' => 'low'], 'messages' => [['role' => 'user', 'content' => $prompt]]], 120);
    if (!$ok) return [false, $err];
    $seq = gJson($text); if (!is_array($seq) || !$seq) return [false, 'Could not draft — try again'];
    return [true, ['contact' => (int)$contactIdx, 'lang' => $lang, 'steps' => array_values($seq), 'drafted_at' => gNow()]];
}

/** Approve a prospect: create the lead (assigned by rotation) + marketing contacts. Needs an email or a mobile number. */
function saApprove(PDO $pdo, array $p) {
    $contacts = saReachable($p['contacts']);
    if (!$contacts) return [false, 'This prospect has no email or mobile number, so it can\'t be approved. Add a contact first.'];
    $main = null; foreach ($contacts as $c) if ($c['email'] !== '') { $main = $c; break; } if (!$main) $main = $contacts[0];
    $leadId = $p['lead_id'];
    if (!$leadId) {
        $notes = "Found by the Sales agent.\n\nWhy: {$p['why_fit']}\nSignals: " . implode('; ', (array)$p['signals']) . "\nWebsite: {$p['website']}\n\nContacts:\n"
            . implode("\n", array_map(fn($c) => '- ' . trim(($c['name'] ?? '') . ' · ' . ($c['role'] ?? '') . ' · ' . ($c['email'] ?? '') . ' ' . ($c['phone'] ?? '') . ' ' . ($c['profile_url'] ?? '')), $contacts));
        $am = null; try { require_once __DIR__ . '/reply-bot-lib.php'; $am = function_exists('assignLeadRotation') ? assignLeadRotation($pdo) : null; } catch (Throwable $e) {}
        try {
            $pdo->prepare("INSERT INTO leads (name, company, phone, email, source, status, platforms, notes, category, assigned_to, assigned_at) VALUES (?,?,?,?,'sales_agent','new',?,?,'lead',?,?)")
                ->execute([$main['name'] ?: $p['company'], $p['company'], $main['phone'] ?: null, $main['email'] ?: null, json_encode(['website']), $notes, $am['email'] ?? null, $am ? gNow() : null]);
        } catch (Throwable $e) {
            $pdo->prepare("INSERT INTO leads (name, phone, source, status, platforms, notes, category) VALUES (?,?,'sales_agent','new',?,?,'lead')")
                ->execute([$p['company'], $main['phone'] ?: null, json_encode(['website']), $notes . "\nEmail: " . ($main['email'] ?? '')]);
        }
        $leadId = (string)$pdo->lastInsertId();
    }
    foreach ($contacts as $c) gUpsertContact($pdo, ['email' => $c['email'], 'phone' => $c['phone'], 'name' => $c['name'] ?? null, 'company' => $p['company'], 'source' => 'sales_agent', 'tags' => 'prospect', 'consent' => 'lead', 'consent_note' => 'B2B prospect (public business contact)', 'lead_id' => $leadId]);
    $pdo->prepare("UPDATE sales_prospects SET status=IF(status='new','approved',status), lead_id=?, updated_at=? WHERE id=?")->execute([$leadId, gNow(), $p['id']]);
    return [true, $leadId];
}

/** One outreach email (step 1–3) with every safety check: opt-out, duplicates, daily cap. Returns [ok, error|null]. */
function saSendStep(PDO $pdo, array $p, $to, $step, $subject, $body, $by, $cap) {
    $to = gEmail($to); if (!$to || trim($subject) === '' || trim($body) === '') return [false, 'Recipient, subject and body are required'];
    $today = (int)$pdo->query("SELECT COUNT(*) FROM sales_sends WHERE sent_at >= CURDATE()")->fetchColumn();
    if ($today >= $cap) return [false, "Daily limit reached ($cap)"];
    $u = $pdo->prepare("SELECT unsub_email FROM mkt_contacts WHERE email=?"); $u->execute([$to]);
    if ((int)$u->fetchColumn() === 1) return [false, 'This person asked not to be contacted.'];
    $dup = $pdo->prepare("SELECT COUNT(*) FROM sales_sends WHERE prospect_id=? AND to_email=? AND step=?"); $dup->execute([$p['id'], $to, $step]);
    if ((int)$dup->fetchColumn() > 0) return [false, "Email $step was already sent"];
    gUpsertContact($pdo, ['email' => $to, 'company' => $p['company'], 'source' => 'sales_agent', 'tags' => 'prospect', 'consent' => 'lead', 'consent_note' => 'B2B prospect']);
    $optOut = gBase() . '/sales-optout.php?' . http_build_query(['e' => $to, 's' => gSign('so' . $to)]);
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#111">' . nl2br(gH($body))
        . '<p style="margin-top:22px;font-size:11px;color:#9ca3af">Not interested? <a href="' . gH($optOut) . '" style="color:#9ca3af">Let me know with one click</a> and I won\'t email again.</p></div>';
    [$ok, $pid, $err] = gSendEmail(defined('SALES_FROM') ? SALES_FROM : 'Admepro <sales@admepro.com>', $to, $subject, $html, defined('SALES_REPLY_TO') ? SALES_REPLY_TO : null, $optOut);
    if (!$ok) return [false, 'Send failed: ' . $err];
    $pdo->prepare("INSERT INTO sales_sends (prospect_id, to_email, step, subject, provider_id, sent_by, sent_at) VALUES (?,?,?,?,?,?,?)")->execute([$p['id'], $to, $step, mb_substr($subject, 0, 250), $pid, $by, gNow()]);
    $pdo->prepare("UPDATE sales_prospects SET status=IF(status IN ('new','approved'),'contacted',status), updated_at=? WHERE id=?")->execute([gNow(), $p['id']]);
    return [true, null];
}
