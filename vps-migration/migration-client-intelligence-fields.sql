-- Client Intelligence (Settings > Scheduling tab) saves a wide set of
-- fields — this fills in any that may be missing from an older version of
-- the client_intelligence table, which is the most likely reason "Save"
-- appears to work (no visible error) but a hard refresh reverts everything:
-- ue()/ce() never throw on a DB error, they just log an "Update Failed" /
-- "Save Failed" row to Activity Log and silently keep the old optimistic
-- UI state until the next full reload from the server. Safe to re-run —
-- every column uses IF NOT EXISTS (MySQL 8.0.29+). Run once:
--   mysql -u socialflow_app -p socialflow < migration-client-intelligence-fields.sql

ALTER TABLE client_intelligence
  ADD COLUMN IF NOT EXISTS preferred_platforms TEXT,
  ADD COLUMN IF NOT EXISTS best_posting_days TEXT,
  ADD COLUMN IF NOT EXISTS avoid_weekends TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS posting_frequency INT DEFAULT 3,
  ADD COLUMN IF NOT EXISTS instagram_best_time VARCHAR(10),
  ADD COLUMN IF NOT EXISTS facebook_best_time VARCHAR(10),
  ADD COLUMN IF NOT EXISTS tiktok_best_time VARCHAR(10),
  ADD COLUMN IF NOT EXISTS linkedin_best_time VARCHAR(10),
  ADD COLUMN IF NOT EXISTS instagram_time_mode VARCHAR(20) DEFAULT 'manual',
  ADD COLUMN IF NOT EXISTS facebook_time_mode VARCHAR(20) DEFAULT 'manual',
  ADD COLUMN IF NOT EXISTS tiktok_time_mode VARCHAR(20) DEFAULT 'manual',
  ADD COLUMN IF NOT EXISTS linkedin_time_mode VARCHAR(20) DEFAULT 'manual',
  ADD COLUMN IF NOT EXISTS active_hours VARCHAR(20) DEFAULT 'evening',
  ADD COLUMN IF NOT EXISTS timezone VARCHAR(50) DEFAULT 'Africa/Cairo',
  ADD COLUMN IF NOT EXISTS peak_engagement_days TEXT,
  ADD COLUMN IF NOT EXISTS preferred_content_types TEXT,
  ADD COLUMN IF NOT EXISTS preferred_pillars TEXT,
  ADD COLUMN IF NOT EXISTS best_performing_type VARCHAR(30),
  ADD COLUMN IF NOT EXISTS best_performing_day VARCHAR(20),
  ADD COLUMN IF NOT EXISTS avg_engagement_rate DECIMAL(6,2);
