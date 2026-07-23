-- Client Intelligence (Settings > Scheduling tab) saves a wide set of
-- fields — this fills in any that may be missing from an older version of
-- the client_intelligence table, which is the most likely reason "Save"
-- appears to work (no visible error) but a hard refresh reverts everything:
-- ue()/ce() never throw on a DB error, they just log an "Update Failed" /
-- "Save Failed" row to Activity Log and silently keep the old optimistic
-- UI state until the next full reload from the server.
--
-- This server's MySQL/MariaDB doesn't support "ADD COLUMN IF NOT EXISTS",
-- so each column is its own statement — run with --force so a "Duplicate
-- column name" error on any column that already exists is skipped instead
-- of stopping the whole script:
--   mysql -u socialflow_app -p --force socialflow < migration-client-intelligence-fields.sql

ALTER TABLE client_intelligence ADD COLUMN preferred_platforms TEXT;
ALTER TABLE client_intelligence ADD COLUMN best_posting_days TEXT;
ALTER TABLE client_intelligence ADD COLUMN avoid_weekends TINYINT(1) DEFAULT 0;
ALTER TABLE client_intelligence ADD COLUMN posting_frequency INT DEFAULT 3;
ALTER TABLE client_intelligence ADD COLUMN instagram_best_time VARCHAR(10);
ALTER TABLE client_intelligence ADD COLUMN facebook_best_time VARCHAR(10);
ALTER TABLE client_intelligence ADD COLUMN tiktok_best_time VARCHAR(10);
ALTER TABLE client_intelligence ADD COLUMN linkedin_best_time VARCHAR(10);
ALTER TABLE client_intelligence ADD COLUMN instagram_time_mode VARCHAR(20) DEFAULT 'manual';
ALTER TABLE client_intelligence ADD COLUMN facebook_time_mode VARCHAR(20) DEFAULT 'manual';
ALTER TABLE client_intelligence ADD COLUMN tiktok_time_mode VARCHAR(20) DEFAULT 'manual';
ALTER TABLE client_intelligence ADD COLUMN linkedin_time_mode VARCHAR(20) DEFAULT 'manual';
ALTER TABLE client_intelligence ADD COLUMN active_hours VARCHAR(20) DEFAULT 'evening';
ALTER TABLE client_intelligence ADD COLUMN timezone VARCHAR(50) DEFAULT 'Africa/Cairo';
ALTER TABLE client_intelligence ADD COLUMN peak_engagement_days TEXT;
ALTER TABLE client_intelligence ADD COLUMN preferred_content_types TEXT;
ALTER TABLE client_intelligence ADD COLUMN preferred_pillars TEXT;
ALTER TABLE client_intelligence ADD COLUMN best_performing_type VARCHAR(30);
ALTER TABLE client_intelligence ADD COLUMN best_performing_day VARCHAR(20);
ALTER TABLE client_intelligence ADD COLUMN avg_engagement_rate DECIMAL(6,2);
