-- Brand Guidelines now supports multiple logo variants (not just one
-- logo_url) — light/dark background, icon-only, horizontal, etc. Stored as
-- a JSON array [{label, url}]. logo_url (from the earlier migration) is
-- kept in sync with the first variant for any older code still reading it.
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-client-brand-logos.sql

ALTER TABLE client_knowledge ADD COLUMN logos TEXT;
