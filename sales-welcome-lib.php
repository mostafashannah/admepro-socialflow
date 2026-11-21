<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }
// ================================================================
// Welcome emails (Gulf + Egypt), built on the newsletter system.
//   - Each market has ONE real newsletter campaign (Marketing → Campaigns) edited in the normal newsletter editor (blocks, preview, test send).
//     It is never bulk-sent: its audience carries {"auto":"welcome","market":"gulf|egypt"}.
//   - Every day the Sales agent finds leads; for each lead with an email this library drafts a pending row in sales_welcome (the review queue).
//   - The team approves a row; it is then rendered with the campaign's blocks and sent through the newsletter pipeline (mkt_sends), so
//     open / click / unsubscribe tracking and the campaign statistics work exactly like a newsletter.
// ================================================================
require_once __DIR__ . '/sales-lib.php';

const SW_GULF = ['uae', 'united arab emirates', 'dubai', 'abu dhabi', 'sharjah', 'saudi', 'ksa', 'riyadh', 'jeddah', 'kuwait', 'qatar', 'doha', 'bahrain', 'manama', 'oman', 'muscat', 'gcc', 'gulf'];

function swMarket($country): string {
    $c = strtolower(trim((string)$country));
    foreach (SW_GULF as $g) if ($c !== '' && str_contains($c, $g)) return 'gulf';
    return 'egypt';
}

/** Starting content of the two campaigns. Edited afterwards in the newsletter editor; this is only used the first time. */
function swDefaultCampaign(string $market): array {
    $about = 'admepro is a creative and digital marketing agency based in Cairo. We build brands and run their social media, content and video production, websites and paid ads, so you get one team for everything your brand shows online.';
    $blocks = [
        ['type' => 'text', 'text' => $market === 'gulf' ? 'Dear {{first_name}},' : 'Hi {{first_name}},'],
        ['type' => 'text', 'text' => $about, 'section' => 'intro'],
        ['type' => 'card', 'title' => 'AI project: SocialFlow, our AI content platform', 'text' => 'We built our own platform where an AI assistant plans, writes, designs, schedules and reports your social content, and answers your customers on WhatsApp and Instagram. You see everything in one place.', 'url' => 'https://admepro.com', 'button' => 'Learn more', 'image' => '', 'section' => 'ai'],
        ['type' => 'card', 'title' => 'Social media management', 'text' => 'Content planning, designs, reels, daily posting and community replies, with a monthly performance report.', 'url' => 'https://admepro.com', 'button' => 'Learn more', 'image' => '', 'section' => 'services'],
        ['type' => 'card', 'title' => 'Brand and video production', 'text' => 'Brand identity, reels and campaign videos shot and edited by our in-house team.', 'url' => 'https://admepro.com', 'button' => 'Learn more', 'image' => '', 'section' => 'services'],
        ['type' => 'text', 'text' => $market === 'gulf'
            ? 'If it would help {{company}}, one of our team will be glad to share ideas for your brand. Reply to this email or message us on WhatsApp.'
            : 'If you would like ideas for {{company}}, just reply here or message us on WhatsApp and one of our team will contact you.'],
        ['type' => 'button', 'text' => 'Chat with us on WhatsApp', 'url' => 'https://wa.me/201000531842?text=Hi%20admepro%2C%20I%27m%20interested%20in%20your%20services'],
    ];
    return ['name' => $market === 'gulf' ? 'Gulf welcome email' : 'Egypt welcome email',
        'subject' => $market === 'gulf' ? 'Welcome to admepro, a creative team for {{company}}' : 'Hi {{first_name}}, meet admepro: marketing for {{company}}',
        'preheader' => 'Branding, social media, video and an AI platform under one roof.', 'blocks' => $blocks];
}

function swCampRow(array $r): array { $r['blocks'] = json_decode((string)$r['blocks'], true) ?: []; $r['audience'] = json_decode((string)$r['audience'], true) ?: []; $r['wa_params'] = json_decode((string)$r['wa_params'], true) ?: []; return $r; }

/** The welcome campaign of a market (created on first use). Returns the decoded campaign row. */
function swCampaign(PDO $pdo, string $market): array {
    $market = $market === 'gulf' ? 'gulf' : 'egypt';
    $q = $pdo->prepare("SELECT * FROM mkt_campaigns WHERE audience LIKE ? AND audience LIKE ? ORDER BY id LIMIT 1"); $q->execute(['%"auto":"welcome"%', '%"market":"' . $market . '"%']);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $d = swDefaultCampaign($market);
        $pdo->prepare("INSERT INTO mkt_campaigns (channel, name, subject, preheader, blocks, wa_params, audience, status, created_by, created_at) VALUES ('email',?,?,?,?,'[]',?,'draft','sales-agent',?)")
            ->execute([$d['name'], $d['subject'], $d['preheader'], json_encode($d['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode(['auto' => 'welcome', 'market' => $market, 'consent' => ['opt_in', 'lead']]), gNow()]);
        $q = $pdo->prepare("SELECT * FROM mkt_campaigns WHERE id=?"); $q->execute([(int)$pdo->lastInsertId()]); $r = $q->fetch(PDO::FETCH_ASSOC);
    }
    return swCampRow($r);
}

/** The blocks with the lead's one personal sentence added after the greeting. */
function swBlocks(array $camp, ?string $personal): array {
    $blocks = $camp['blocks']; $personal = trim((string)$personal);
    if ($personal !== '') array_splice($blocks, min(1, count($blocks)), 0, [['type' => 'text', 'text' => $personal]]);
    return $blocks;
}

/** One sentence tying the email to this company (from why_fit / signals). Falls back to '': the email works without it. */
function swPersonalLine(array $p): string {
    $prompt = "Write ONE sentence (max 28 words), plain text, no emojis, for a first email from the agency admepro to {$p['company']} ({$p['industry']}, {$p['country']}). It should show we looked at their business, using only these facts: "
        . trim(($p['why_fit'] ?? '') . ' ' . implode('; ', (array)($p['signals'] ?? []))) . "\nNo flattery, no claims about results, no questions. Return only the sentence.";
    try { [$ok, $text] = gClaude(['max_tokens' => 120, 'output_config' => ['effort' => 'low'], 'messages' => [['role' => 'user', 'content' => $prompt]]], 60); } catch (Throwable $e) { return ''; }
    return $ok ? mb_substr(trim(trim((string)$text), "\"' \n"), 0, 300) : '';
}

/** Create the pending welcome row for a prospect (once per prospect + email). Returns the new id or null. */
function swCreateDraft(PDO $pdo, array $p, bool $withAi = true): ?int {
    $c = null; foreach ((array)$p['contacts'] as $x) if (!empty($x['email'])) { $c = $x; break; }
    if (!$c) return null; $to = gEmail($c['email']); if (!$to) return null;
    $u = $pdo->prepare("SELECT unsub_email FROM mkt_contacts WHERE email=?"); $u->execute([$to]); if ((int)$u->fetchColumn() === 1) return null;
    $ex = $pdo->prepare("SELECT id FROM sales_welcome WHERE prospect_id=? AND to_email=?"); $ex->execute([$p['id'], $to]); if ($ex->fetchColumn()) return null;
    $m = swMarket($p['country'] ?? ''); $camp = swCampaign($pdo, $m);
    $subject = gMergeTags($camp['subject'], ['name' => $c['name'] ?? '', 'company' => $p['company']]);
    $line = $withAi ? swPersonalLine($p) : '';
    $pdo->prepare("INSERT INTO sales_welcome (prospect_id, to_email, to_name, company, market, subject, personal_line, status, created_at) VALUES (?,?,?,?,?,?,?,'pending',?)")
        ->execute([$p['id'], $to, $c['name'] ?? null, mb_substr((string)$p['company'], 0, 200), $m, mb_substr($subject, 0, 250), $line, gNow()]);
    return (int)$pdo->lastInsertId();
}

/** Subject + HTML of a stored welcome row, built from its campaign (the same renderer the newsletter uses). */
function swRenderRow(PDO $pdo, array $row, $sendId = null): array {
    $camp = swCampaign($pdo, $row['market']);
    $contact = ['name' => $row['to_name'] ?? '', 'company' => $row['company'] ?? ''];
    $camp['blocks'] = swBlocks($camp, $row['personal_line'] ?? '');
    $subject = trim((string)$row['subject']) !== '' ? $row['subject'] : gMergeTags($camp['subject'], $contact);
    return [$subject, gRenderEmail($camp, $contact, $sendId)];
}

/** Send an approved welcome email through the newsletter pipeline (mkt_sends). Honors opt-out and the shared daily cap. Returns [ok, error|null]. */
function swSend(PDO $pdo, int $id, string $by, int $cap): array {
    $st = $pdo->prepare("SELECT * FROM sales_welcome WHERE id=?"); $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [false, 'Not found'];
    if ($row['sent_at']) return [false, 'Already sent'];
    $to = gEmail($row['to_email']); if (!$to) return [false, 'Bad email address'];
    $u = $pdo->prepare("SELECT unsub_email FROM mkt_contacts WHERE email=?"); $u->execute([$to]); if ((int)$u->fetchColumn() === 1) return [false, 'This person asked not to be contacted.'];
    $today = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM sales_sends WHERE sent_at >= CURDATE()) + (SELECT COUNT(*) FROM sales_welcome WHERE sent_at >= CURDATE())")->fetchColumn();
    if ($today >= $cap) return [false, "Daily limit reached ($cap). Continue tomorrow: this protects the sending domain."];
    $camp = swCampaign($pdo, $row['market']);
    $cid = gUpsertContact($pdo, ['email' => $to, 'name' => $row['to_name'], 'company' => $row['company'], 'source' => 'sales_agent', 'tags' => 'prospect', 'consent' => 'lead', 'consent_note' => 'B2B prospect']);
    if (!$cid) return [false, 'Could not save the contact'];
    $pdo->prepare("INSERT IGNORE INTO mkt_sends (campaign_id, contact_id, to_addr, status) VALUES (?,?,?,'queued')")->execute([$camp['id'], $cid, $to]);
    $q = $pdo->prepare("SELECT id, status FROM mkt_sends WHERE campaign_id=? AND contact_id=?"); $q->execute([$camp['id'], $cid]); $send = $q->fetch(PDO::FETCH_ASSOC);
    if (!$send) return [false, 'Could not queue the email'];
    if ($send['status'] === 'sent') return [false, 'This person already received this email'];
    [$subject, $html] = swRenderRow($pdo, $row, (int)$send['id']);
    $unsub = gBase() . '/marketing-unsub.php?' . http_build_query(['id' => $send['id'], 's' => gSign('u' . $send['id'])]);
    [$ok, $pid, $err] = gSendEmail(defined('SALES_FROM') ? SALES_FROM : 'Admepro <sales@admepro.com>', $to, $subject, $html, defined('SALES_REPLY_TO') ? SALES_REPLY_TO : null, $unsub);
    $pdo->prepare("UPDATE mkt_sends SET status=?, provider_id=?, error=?, sent_at=NOW() WHERE id=?")->execute([$ok ? 'sent' : 'failed', $pid, $ok ? null : mb_substr((string)$err, 0, 490), $send['id']]);
    if (!$ok) { $pdo->prepare("UPDATE sales_welcome SET status='failed', error=? WHERE id=?")->execute([mb_substr((string)$err, 0, 390), $id]); return [false, 'Send failed: ' . $err]; }
    $pdo->prepare("UPDATE sales_welcome SET status='approved', provider_id=?, reviewed_by=?, reviewed_at=?, sent_at=?, error=NULL WHERE id=?")->execute([$pid, $by, gNow(), gNow(), $id]);
    $pdo->prepare("UPDATE sales_prospects SET status=IF(status IN ('new','approved'),'contacted',status), updated_at=? WHERE id=?")->execute([gNow(), $row['prospect_id']]);
    return [true, null];
}
