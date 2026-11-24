<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only script: never runnable from a browser
/**
 * The file a single post / video / reel / website article publishes (same rule as publishMediaUrl() in app.jsx):
 *  1. the file the account team picked (design_assets entry with pick:true);
 *  2. otherwise the LAST file added in the LATEST submission: submissions are the "round" numbers stamped when work is pushed to review,
 *     files not submitted yet count as the newest, and inside a round the last one added wins (never the first submission's file);
 *  3. posts with no submissions at all (Ready Content): the last linked address, else the last file.
 */
function sf_publish_media_url(array $urls, array $assets): string {
    foreach ($assets as $a) {
        if (is_array($a) && !empty($a['pick']) && ($a['kind'] ?? '') !== 'story' && !empty($a['url'] ?? $a['file_url'] ?? '')) return (string)($a['url'] ?? $a['file_url']);
    }
    $best = -1; $bestRank = -1; $anyRound = false;
    foreach ($assets as $i => $a) {
        if (!is_array($a) || ($a['kind'] ?? '') === 'story' || ($a['type'] ?? '') === 'link' || empty($a['url'] ?? $a['file_url'] ?? '')) continue;
        $r = !empty($a['round']) ? (int)$a['round'] : PHP_INT_MAX;
        if (!empty($a['round'])) $anyRound = true;
        if ($r >= $bestRank) { $bestRank = $r; $best = $i; }
    }
    if ($best >= 0 && $anyRound) return (string)($assets[$best]['url'] ?? $assets[$best]['file_url']);
    if ($urls) return (string)end($urls);
    if ($assets) { $l = end($assets); return (string)($l['url'] ?? $l['file_url'] ?? ''); }
    return '';
}
