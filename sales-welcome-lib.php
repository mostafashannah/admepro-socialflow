<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }
// ================================================================
// Welcome emails (Gulf + Egypt). Each day the sales agent finds leads; for every lead with an email this library drafts ONE welcome
// email (status 'pending'). The team reviews it in Growth → Sales agent → Welcome emails; only an approved email is sent.
// Every email carries: a short agency intro, one AI project + other projects, ALL social links and the WhatsApp link.
// Opens and clicks are tracked (sales-track.php); replies/feedback are marked by the team.
// ================================================================
require_once __DIR__ . '/sales-lib.php';

const SW_GULF = ['uae', 'united arab emirates', 'dubai', 'abu dhabi', 'sharjah', 'saudi', 'saudi arabia', 'ksa', 'riyadh', 'jeddah', 'kuwait', 'qatar', 'doha', 'bahrain', 'manama', 'oman', 'muscat', 'gcc', 'gulf'];

function swDefaults(): array {
    $about = 'admepro is a creative and digital marketing agency based in Cairo. We build brands and run their social media, content and video production, websites and paid ads, so you get one team for everything your brand shows online.';
    $projects = [
        ['tag' => 'AI project', 'title' => 'SocialFlow — our AI content platform', 'text' => 'We built our own platform where an AI assistant plans, writes, designs, schedules and reports your social content, and answers your customers on WhatsApp and Instagram. You see everything in one place.', 'url' => 'https://admepro.com'],
        ['tag' => 'Service', 'title' => 'Social media management', 'text' => 'Content planning, designs, reels, daily posting and community replies, with a monthly performance report.', 'url' => 'https://admepro.com'],
        ['tag' => 'Service', 'title' => 'Brand and video production', 'text' => 'Brand identity, reels and campaign videos shot and edited by our in-house team.', 'url' => 'https://admepro.com'],
    ];
    return [
        'socials' => ['website' => 'https://admepro.com', 'instagram' => 'https://www.instagram.com/admeproagency', 'facebook' => 'https://facebook.com/admepro',
            'linkedin' => 'https://www.linkedin.com/company/5040327/', 'tiktok' => '', 'x' => '', 'youtube' => '', 'whatsapp' => 'https://wa.me/201000531842?text=Hi%20admepro%2C%20I%27m%20interested%20in%20your%20services'],
        'gulf' => ['subject' => 'Welcome to admepro — a creative team for {company}', 'greeting' => 'Dear {name},', 'about' => $about, 'projects' => $projects,
            'cta' => 'If it would help {company}, one of our team will be glad to share ideas for your brand. Reply to this email or message us on WhatsApp.'],
        'egypt' => ['subject' => 'Hi {name}, meet admepro — marketing for {company}', 'greeting' => 'Hi {name},', 'about' => $about, 'projects' => $projects,
            'cta' => 'If you would like ideas for {company}, just reply here or message us on WhatsApp and one of our team will contact you.'],
    ];
}

function swSettings(PDO $pdo): array {
    $d = swDefaults();
    try { $v = $pdo->query("SELECT v FROM seo_settings WHERE k='sales_welcome'")->fetchColumn(); $j = $v ? json_decode($v, true) : null; } catch (Throwable $e) { $j = null; }
    if (!is_array($j)) return $d;
    foreach (['gulf', 'egypt'] as $m) { $d[$m] = array_merge($d[$m], is_array($j[$m] ?? null) ? $j[$m] : []); if (!is_array($d[$m]['projects'])) $d[$m]['projects'] = swDefaults()[$m]['projects']; }
    $d['socials'] = array_merge($d['socials'], is_array($j['socials'] ?? null) ? $j['socials'] : []);
    return $d;
}

function swMarket($country): string {
    $c = strtolower(trim((string)$country));
    foreach (SW_GULF as $g) if ($c !== '' && str_contains($c, $g)) return 'gulf';
    return 'egypt';
}

function swFill($s, array $v): string { return strtr((string)$s, ['{name}' => $v['name'], '{company}' => $v['company']]); }

/** A tracked link: counts the click, then redirects to $url. */
function swLink($id, $url, $label): string {
    if (!preg_match('#^https?://#i', (string)$url)) return '';
    return gBase() . '/sales-track.php?' . http_build_query(['c' => (int)$id, 'u' => $url, 'l' => $label, 's' => gSign('wc' . (int)$id . $url)]);
}

/** Subject + HTML for a stored welcome row. $to_name may be empty. Pass $forPreview = true to skip tracking pixel. */
function swRender(PDO $pdo, array $row, bool $forPreview = false): array {
    $S = swSettings($pdo); $m = $row['market'] === 'gulf' ? 'gulf' : 'egypt'; $T = $S[$m];
    $first = trim(explode(' ', trim((string)($row['to_name'] ?? '')))[0] ?? '');
    $v = ['name' => $first !== '' ? $first : 'there', 'company' => trim((string)$row['company']) !== '' ? $row['company'] : 'your brand'];
    $id = (int)$row['id'];
    $subject = trim((string)$row['subject']) !== '' ? $row['subject'] : swFill($T['subject'], $v);
    $e = fn($s) => gH($s);
    $btn = fn($url, $label, $bg) => '<a href="' . $e(swLink($id, $url, $label)) . '" style="display:inline-block;margin:4px 6px 4px 0;padding:10px 18px;background:' . $bg . ';color:#fff;text-decoration:none;font-weight:700;font-size:13px;border-radius:999px">' . $e($label) . '</a>';

    $projects = '';
    foreach (array_slice((array)$T['projects'], 0, 3) as $p) {
        if (trim((string)($p['title'] ?? '')) === '') continue;
        $projects .= '<tr><td style="padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px"><div style="font-size:11px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.04em">' . $e($p['tag'] ?? '') . '</div>'
            . '<div style="font-size:15px;font-weight:700;color:#111;margin:3px 0">' . $e($p['title']) . '</div><div style="font-size:13px;color:#374151;line-height:1.55">' . $e($p['text'] ?? '') . '</div>'
            . (!empty($p['url']) ? '<div style="margin-top:6px"><a href="' . $e(swLink($id, $p['url'], 'Project: ' . $p['title'])) . '" style="font-size:12px;color:#2563eb">Learn more →</a></div>' : '') . '</td></tr><tr><td style="height:8px"></td></tr>';
    }
    $soc = ''; $labels = ['website' => 'Website', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'x' => 'X', 'youtube' => 'YouTube'];
    foreach ($labels as $k => $lab) if (!empty($S['socials'][$k])) $soc .= '<a href="' . $e(swLink($id, $S['socials'][$k], $lab)) . '" style="color:#374151;font-size:13px;text-decoration:underline;margin-right:14px">' . $lab . '</a>';
    $to = gEmail($row['to_email']) ?: '';
    $optOut = gBase() . '/sales-optout.php?' . http_build_query(['e' => $to, 's' => gSign('so' . $to)]);
    $personal = trim((string)($row['personal_line'] ?? ''));
    $html = '<div style="background:#f3f4f6;padding:18px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#fff;border-radius:14px;font-family:Arial,sans-serif;color:#111"><tr><td style="padding:22px 26px">'
        . '<div style="font-size:20px;font-weight:800;letter-spacing:-.3px">admepro</div><div style="height:3px;width:42px;background:#7c3aed;margin:8px 0 18px"></div>'
        . '<p style="font-size:14px;line-height:1.65;margin:0 0 12px">' . $e(swFill($T['greeting'], $v)) . '</p>'
        . ($personal !== '' ? '<p style="font-size:14px;line-height:1.65;margin:0 0 12px">' . $e($personal) . '</p>' : '')
        . '<p style="font-size:14px;line-height:1.65;margin:0 0 16px">' . $e(swFill($T['about'], $v)) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $projects . '</table>'
        . '<p style="font-size:14px;line-height:1.65;margin:10px 0 14px">' . $e(swFill($T['cta'], $v)) . '</p>'
        . '<div>' . $btn($S['socials']['whatsapp'] ?? '', 'Chat with us on WhatsApp', '#16a34a') . '</div>'
        . '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #e5e7eb"><div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">Follow us</div>' . $soc . '</div>'
        . '<p style="margin:18px 0 0;font-size:11px;color:#9ca3af">Not interested? <a href="' . $e($optOut) . '" style="color:#9ca3af">Let us know with one click</a> and we won\'t email again.</p>'
        . '</td></tr></table>'
        . ($forPreview || !$id ? '' : '<img src="' . $e(gBase() . '/sales-track.php?' . http_build_query(['o' => $id, 's' => gSign('wo' . $id)])) . '" width="1" height="1" alt="" style="display:none">') . '</div>';
    return [$subject, $html];
}

/** One sentence tying the email to this company (from why_fit / signals). Falls back to '' — the email works without it. */
function swPersonalLine(array $p): string {
    $prompt = "Write ONE sentence (max 28 words), plain text, no emojis, for a first email from the agency admepro to {$p['company']} ({$p['industry']}, {$p['country']}). It should show we looked at their business, using only these facts: "
        . trim(($p['why_fit'] ?? '') . ' ' . implode('; ', (array)($p['signals'] ?? []))) . "\nNo flattery, no claims about results, no questions. Return only the sentence.";
    try { [$ok, $text] = gClaude(['max_tokens' => 120, 'output_config' => ['effort' => 'low'], 'messages' => [['role' => 'user', 'content' => $prompt]]], 60); } catch (Throwable $e) { return ''; }
    return $ok ? mb_substr(trim(trim((string)$text), "\"' \n"), 0, 300) : '';
}

/** Create the pending welcome email for a prospect (once per prospect + email). Returns the new id or null. */
function swCreateDraft(PDO $pdo, array $p, bool $withAi = true): ?int {
    $c = null; foreach ((array)$p['contacts'] as $x) if (!empty($x['email'])) { $c = $x; break; }
    if (!$c) return null; $to = gEmail($c['email']); if (!$to) return null;
    $u = $pdo->prepare("SELECT unsub_email FROM mkt_contacts WHERE email=?"); $u->execute([$to]); if ((int)$u->fetchColumn() === 1) return null;
    $ex = $pdo->prepare("SELECT id FROM sales_welcome WHERE prospect_id=? AND to_email=?"); $ex->execute([$p['id'], $to]); if ($ex->fetchColumn()) return null;
    $m = swMarket($p['country'] ?? ''); $S = swSettings($pdo);
    $first = trim(explode(' ', trim((string)($c['name'] ?? '')))[0] ?? '');
    $subject = swFill($S[$m]['subject'], ['name' => $first !== '' ? $first : 'there', 'company' => $p['company']]);
    $line = $withAi ? swPersonalLine($p) : '';
    $pdo->prepare("INSERT INTO sales_welcome (prospect_id, to_email, to_name, company, market, subject, personal_line, status, created_at) VALUES (?,?,?,?,?,?,?,'pending',?)")
        ->execute([$p['id'], $to, $c['name'] ?? null, mb_substr((string)$p['company'], 0, 200), $m, mb_substr($subject, 0, 250), $line, gNow()]);
    return (int)$pdo->lastInsertId();
}

/** Send an approved welcome email. Honors opt-out and the shared daily cap. Returns [ok, error|null]. */
function swSend(PDO $pdo, int $id, string $by, int $cap): array {
    $st = $pdo->prepare("SELECT * FROM sales_welcome WHERE id=?"); $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [false, 'Not found'];
    if ($row['sent_at']) return [false, 'Already sent'];
    $to = gEmail($row['to_email']); if (!$to) return [false, 'Bad email address'];
    $u = $pdo->prepare("SELECT unsub_email FROM mkt_contacts WHERE email=?"); $u->execute([$to]); if ((int)$u->fetchColumn() === 1) return [false, 'This person asked not to be contacted.'];
    $today = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM sales_sends WHERE sent_at >= CURDATE()) + (SELECT COUNT(*) FROM sales_welcome WHERE sent_at >= CURDATE())")->fetchColumn();
    if ($today >= $cap) return [false, "Daily limit reached ($cap). Continue tomorrow — this protects the sending domain."];
    [$subject, $html] = swRender($pdo, $row);
    $optOut = gBase() . '/sales-optout.php?' . http_build_query(['e' => $to, 's' => gSign('so' . $to)]);
    [$ok, $pid, $err] = gSendEmail(defined('SALES_FROM') ? SALES_FROM : 'Admepro <sales@admepro.com>', $to, $subject, $html, defined('SALES_REPLY_TO') ? SALES_REPLY_TO : null, $optOut);
    if (!$ok) { $pdo->prepare("UPDATE sales_welcome SET status='failed', error=? WHERE id=?")->execute([mb_substr((string)$err, 0, 390), $id]); return [false, 'Send failed: ' . $err]; }
    $pdo->prepare("UPDATE sales_welcome SET status='approved', provider_id=?, reviewed_by=?, reviewed_at=?, sent_at=?, error=NULL WHERE id=?")->execute([$pid, $by, gNow(), gNow(), $id]);
    $pdo->prepare("UPDATE sales_prospects SET status=IF(status IN ('new','approved'),'contacted',status), updated_at=? WHERE id=?")->execute([gNow(), $row['prospect_id']]);
    return [true, null];
}
