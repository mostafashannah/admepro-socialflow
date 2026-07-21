-- Admin-adjustable task duration estimates (Settings -> Task Estimates):
-- per-post-type base minutes and per-priority multipliers, used by
-- estimateDuration() instead of the hardcoded defaults when set.
--   mysql -u root -p socialflow < migration-task-durations.sql

ALTER TABLE app_settings
  ADD COLUMN task_durations JSON DEFAULT ('{}');
