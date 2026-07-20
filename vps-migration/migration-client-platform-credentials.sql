-- Per-client saved logins (username/password) for each social platform,
-- shown on the Client page's new "Logins" tab (admin / account manager only).
-- Run once:
--   sudo mysql socialflow < migration-client-platform-credentials.sql

ALTER TABLE clients
  ADD COLUMN platform_credentials JSON DEFAULT ('[]');
