-- "Auto" best-posting-time now predicts a full per-weekday map instead of one
-- flat time (Monday's best hour is rarely the same as Saturday's) — these
-- store that map as JSON, e.g. {"saturday":"11:00","sunday":"18:00",...}.
-- The existing `{platform}_best_time` columns are kept as a single fallback
-- value (used when no weekday map exists yet, or by any older code path).
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-best-times-by-day.sql

ALTER TABLE client_intelligence ADD COLUMN instagram_best_times_by_day TEXT;
ALTER TABLE client_intelligence ADD COLUMN facebook_best_times_by_day TEXT;
ALTER TABLE client_intelligence ADD COLUMN tiktok_best_times_by_day TEXT;
ALTER TABLE client_intelligence ADD COLUMN linkedin_best_times_by_day TEXT;
