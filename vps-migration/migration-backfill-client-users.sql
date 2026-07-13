-- Run once against the live database. Backfills a Client User row for every
-- existing client that has an email but no corresponding client_users row
-- yet (new clients get this automatically going forward as of the
-- "addClient() creates a Client User" change — this covers everyone created
-- before that). Safe to re-run: the NOT EXISTS guard means it only ever
-- inserts once per client.

INSERT INTO client_users (id, client_id, client_name, email, name, role, status, password)
SELECT UUID(), c.id, c.name, c.email, c.name, 'client_admin', 'active', c.portal_password
FROM clients c
WHERE c.email IS NOT NULL AND c.email <> ''
  AND NOT EXISTS (SELECT 1 FROM client_users cu WHERE cu.client_id = c.id);
