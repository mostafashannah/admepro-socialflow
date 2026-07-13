-- Adds a unique key so re-running the cron (or the new backfill mode) for
-- the same integration/platform/day upserts instead of creating duplicate
-- snapshot rows. Run once:
--   sudo mysql socialflow < migration-meta-insights-unique.sql

ALTER TABLE meta_insights_snapshots
  ADD UNIQUE KEY uq_meta_snapshot (integration_id, platform, snapshot_date);
