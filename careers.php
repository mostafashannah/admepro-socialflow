<?php
// ================================================================
// SocialFlow — server-rendered /careers page.
//
// The real careers experience (browsing openings, applying) lives in
// the React SPA (CareersPage in app.jsx) — but a pure client-rendered
// page is close to invisible to anything that fetches raw HTML instead
// of running JavaScript: Google's job-search rich results, and most
// AI answer engines/agents (ChatGPT, Claude, Perplexity, etc.) that
// browse the web. This script queries the real open positions and
// renders full, readable HTML plus per-job JobPosting structured data
// BEFORE any JS runs, then loads the same app.js bundle at the bottom
// so a real visitor still gets the full interactive apply flow — React
// simply takes over the #root div once it mounts.
//
// Routed here (instead of falling through to the SPA's index.html) by
// the ^careers/?$ rule in .htaccess, same pattern already used for
// /features -> features.html.
// ================================================================

require_once __DIR__ . '/config.php';

$openings = [];
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $openings = $pdo->query("SELECT id, title, department, location, employment_type, description, requirements, closing_date, created_at FROM job_openings WHERE status = 'open' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[careers.php] failed to load openings: ' . $e->getMessage());
}

function careersEmploymentTypeLabel(string $t): string {
    return ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'freelance' => 'Freelance', 'contract' => 'Contract', 'internship' => 'Internship'][$t] ?? 'Full-time';
}
function careersSchemaEmploymentType(string $t): string {
    return ['full_time' => 'FULL_TIME', 'part_time' => 'PART_TIME', 'freelance' => 'CONTRACTOR', 'contract' => 'CONTRACTOR', 'internship' => 'INTERN'][$t] ?? 'FULL_TIME';
}
function careersIso(?string $d): ?string {
    if (!$d) return null;
    $ts = strtotime($d);
    return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
}
function careersPlainText(?string $html): string {
    // Descriptions/requirements are stored as plain text with occasional
    // markdown-ish line breaks, not HTML — strip anything that DOES look
    // like a stray tag before re-encoding, so neither the visible page
    // nor the JSON-LD ever emits unescaped markup.
    return trim(strip_tags((string)$html));
}

$count = count($openings);
$titles = array_map(fn($o) => $o['title'], $openings);
$pageTitle = $count > 0
    ? 'Careers at Admepro — ' . $count . ' Open Position' . ($count === 1 ? '' : 's') . ' | ' . htmlspecialchars(implode(', ', array_slice($titles, 0, 3)))
    : 'Careers at Admepro — Job Openings';
$pageDescription = $count > 0
    ? 'Admepro is hiring: ' . implode(', ', array_slice($titles, 0, 6)) . ($count > 6 ? ', and more' : '') . '. Apply directly online — see full role descriptions, requirements, and how to apply.'
    : 'Admepro careers — check back soon for open positions, or send us your CV for future opportunities.';
$canonicalUrl = 'https://socialflow.admepro.com/careers';

// Read the current app.js?v=NNN straight out of index.html instead of
// hardcoding a version here — otherwise this page would silently start
// serving a stale JS bundle the next time index.html's cache-bust gets
// bumped for an unrelated deploy.
$appJsTag = '/app.js';
$indexHtml = @file_get_contents(__DIR__ . '/index.html');
if ($indexHtml && preg_match('/\/app\.js\?v=\d+/', $indexHtml, $m)) $appJsTag = $m[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover"/>
<meta name="theme-color" content="#d90b2c"/>
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($pageDescription) ?>"/>
<meta name="robots" content="index, follow"/>
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>"/>

<!-- Open Graph / Twitter -->
<meta property="og:type" content="website"/>
<meta property="og:site_name" content="Admepro"/>
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>"/>
<meta property="og:description" content="<?= htmlspecialchars($pageDescription) ?>"/>
<meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>"/>
<meta name="twitter:card" content="summary_large_image"/>
<meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>"/>
<meta name="twitter:description" content="<?= htmlspecialchars($pageDescription) ?>"/>

<link rel="icon" href="/favicon.svg" type="image/svg+xml"/>
<link rel="manifest" href="/manifest.json"/>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- JSON-LD — one JobPosting per open role, the format Google for Jobs
     and AI answer engines/agents parse directly, without needing to
     render or scrape the visual page at all. -->
<?php foreach ($openings as $o): ?>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org/',
    '@type' => 'JobPosting',
    'title' => $o['title'],
    'description' => nl2br(htmlspecialchars(trim(($o['description'] ?? '') . ($o['requirements'] ? "\n\nRequirements:\n" . $o['requirements'] : '')))),
    'identifier' => ['@type' => 'PropertyValue', 'name' => 'Admepro', 'value' => $o['id']],
    'datePosted' => careersIso($o['created_at']),
    'validThrough' => careersIso($o['closing_date']),
    'employmentType' => careersSchemaEmploymentType((string)$o['employment_type']),
    'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Admepro', 'sameAs' => 'https://admepro.com', 'logo' => 'https://socialflow.admepro.com/icon-512.png'],
    'jobLocation' => [
        '@type' => 'Place',
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => $o['location'] ?: 'Cairo', 'addressCountry' => 'EG'],
    ],
    'directApply' => true,
    'url' => $canonicalUrl . '?opening=' . $o['id'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
<?php endforeach; ?>

<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body,#root{height:100%;overflow-x:hidden}
body{font-family:"Montserrat",-apple-system,sans-serif;-webkit-font-smoothing:antialiased;overflow-x:hidden}
img{max-width:100%;display:block}
/* Pre-render styling only — visually close to the SPA's dark theme so
   there's no jarring flash once React mounts and replaces this markup. */
#seo-content{background:#0b0d12;color:#e5e7eb;min-height:100vh;padding:32px 20px 60px}
#seo-content .wrap{max-width:760px;margin:0 auto}
#seo-content h1{font-size:28px;font-weight:800;margin-bottom:8px}
#seo-content .lead{color:#9099ab;font-size:15px;margin-bottom:32px;line-height:1.6}
#seo-content article{background:#151822;border:1px solid #232838;border-radius:14px;padding:24px;margin-bottom:20px}
#seo-content h2{font-size:19px;font-weight:800;margin-bottom:6px}
#seo-content .badges{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
#seo-content .badge{font-size:12px;font-weight:600;color:#c7cad1;background:#232838;border-radius:20px;padding:4px 12px}
#seo-content .body-text{font-size:14px;line-height:1.7;color:#c7cad1;white-space:pre-wrap;margin-bottom:16px}
#seo-content .body-text strong{color:#fff}
#seo-content a.apply{display:inline-block;background:#d90b2c;color:#fff;font-weight:700;font-size:14px;padding:10px 20px;border-radius:8px;text-decoration:none}
#seo-content .empty{color:#9099ab;font-size:15px;text-align:center;padding:60px 0}
</style>
</head>
<body>
<div id="root">
  <!-- Real, crawlable content — replaced by the React app once app.js
       mounts, for visitors with JavaScript. -->
  <div id="seo-content">
    <div class="wrap">
      <h1>Careers at Admepro</h1>
      <p class="lead">We're a social media agency team of strategists, creatives, designers, and account managers. Below are our current open positions — apply directly online, no account required.</p>
      <?php if (!$openings): ?>
        <p class="empty">No open positions right now — check back soon, or send your CV to hello@admepro.com for future opportunities.</p>
      <?php endif; ?>
      <?php foreach ($openings as $o): ?>
        <article>
          <h2><?= htmlspecialchars($o['title']) ?></h2>
          <div class="badges">
            <span class="badge"><?= htmlspecialchars(careersEmploymentTypeLabel((string)$o['employment_type'])) ?></span>
            <?php if ($o['department']): ?><span class="badge"><?= htmlspecialchars($o['department']) ?></span><?php endif; ?>
            <span class="badge"><?= htmlspecialchars($o['location'] ?: 'Cairo, Egypt') ?></span>
          </div>
          <?php if ($o['description']): ?><p class="body-text"><?= nl2br(htmlspecialchars(careersPlainText($o['description']))) ?></p><?php endif; ?>
          <?php if ($o['requirements']): ?><p class="body-text"><strong>Requirements</strong><br/><?= nl2br(htmlspecialchars(careersPlainText($o['requirements']))) ?></p><?php endif; ?>
          <a class="apply" href="/careers?opening=<?= urlencode($o['id']) ?>">Apply for this role</a>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script src="https://unpkg.com/react@18.3.1/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>if(window.pdfjsLib){pdfjsLib.GlobalWorkerOptions.workerSrc="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";}</script>
<script src="<?= htmlspecialchars($appJsTag) ?>"></script>
</body>
</html>
