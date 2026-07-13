-- The system_sessions table already existed on this server, but under a
-- generic catch-all schema (id, created_at, data JSON) instead of the real
-- columns the app reads/writes (user_email, ip_address, browser, os, etc.).
-- The earlier "CREATE TABLE IF NOT EXISTS" migration was a no-op because the
-- table was already present — it never checks/fixes column mismatches. Every
-- session insert has been silently failing ("Unknown column") ever since,
-- which is why Login Sessions/Unique IPs/Countries/IP/OS/Browser have all
-- stayed at 0 or blank. Table had 0 rows at the time this was written, so
-- this just replaces the columns outright — nothing to migrate/preserve.
-- Run once:
--   sudo mysql socialflow < migration-fix-system-sessions-schema.sql

ALTER TABLE system_sessions
  ADD COLUMN user_email TEXT,
  ADD COLUMN user_name TEXT,
  ADD COLUMN user_role VARCHAR(50),
  ADD COLUMN ip_address VARCHAR(64),
  ADD COLUMN country TEXT,
  ADD COLUMN country_code VARCHAR(10),
  ADD COLUMN region TEXT,
  ADD COLUMN city TEXT,
  ADD COLUMN isp TEXT,
  ADD COLUMN org TEXT,
  ADD COLUMN latitude DOUBLE,
  ADD COLUMN longitude DOUBLE,
  ADD COLUMN browser TEXT,
  ADD COLUMN os TEXT,
  ADD COLUMN device_type VARCHAR(20),
  ADD COLUMN screen_resolution VARCHAR(20),
  ADD COLUMN viewport VARCHAR(20),
  ADD COLUMN timezone VARCHAR(64),
  ADD COLUMN language VARCHAR(20),
  ADD COLUMN user_agent TEXT,
  ADD COLUMN login_at TIMESTAMP NULL,
  ADD COLUMN page_url TEXT;

ALTER TABLE system_sessions DROP COLUMN data;
