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
    $found = [];
    foreach ($rows as $r) {
        $c = json_decode((string)$r['credentials'], true) ?: [];
        $hosts = [ws_host((string)($c['site_url'] ?? ''))];
        foreach (preg_split('/[\s,;]+/', (string)($c['site_domains'] ?? '')) as $d) $hosts[] = ws_host($d);
        if (in_array($oh, array_filter($hosts), true)) $found[$r['client_id']] = ['client_id' => $r['client_id'], 'client_name' => (string)$r['client_name'], 'site' => $oh];
    }
    // The same domain registered for two clients would mix their leads: refuse it (nothing is saved) and say so in the Activity Log.
    if (count($found) > 1) {
        try { $pdo->prepare("INSERT INTO activity_logs (id, action, category, details, status, performed_by) VALUES (UUID(), 'Website domain registered for two clients', 'leads', :d, 'error', 'system')")->execute([':d' => $oh . ' → ' . implode(' / ', array_column($found, 'client_name'))]); } catch (Throwable $e) {}
        return null;
    }
    return $found ? array_values($found)[0] : null;
}
