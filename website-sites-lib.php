<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; } // server-only
/**
 * website-sites-lib.php — which websites are allowed to post leads here, and which client each one belongs to.
 * The list comes from the clients' own "Website" integrations (Client → Settings → Integrations → Website): the site address
 * and the optional "Other domains" field (temporary/staging link, www, the real domain…). Adding a domain there is enough:
 * a site moving from a temporary link to its real domain only needs the new domain added, nothing in the code changes.
 */
function ws_host(string $u): string {
    $u = trim($u); if ($u === '') return '';
    if (!preg_match('#^[a-z]+://#i', $u)) $u = 'https://' . $u;
    $h = strtolower((string)parse_url($u, PHP_URL_HOST));
    return preg_replace('/^www\./', '', $h);
}
/** Returns ['client_id', 'client_name', 'site'] when $origin belongs to a client's active Website integration, else null. */
function ws_client_for_origin(PDO $pdo, string $origin): ?array {
    $oh = ws_host($origin); if ($oh === '') return null;
    $rows = $pdo->query("SELECT client_id, client_name, credentials FROM integrations WHERE app_key = 'website' AND status = 'active' AND client_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $c = json_decode((string)$r['credentials'], true) ?: [];
        $hosts = [ws_host((string)($c['site_url'] ?? ''))];
        foreach (preg_split('/[\s,;]+/', (string)($c['site_domains'] ?? '')) as $d) $hosts[] = ws_host($d);
        if (in_array($oh, array_filter($hosts), true)) return ['client_id' => $r['client_id'], 'client_name' => (string)$r['client_name'], 'site' => $oh];
    }
    return null;
}
