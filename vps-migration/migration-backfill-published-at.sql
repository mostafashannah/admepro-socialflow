-- Backfill: every post moved to Published stage manually in the app (as
-- opposed to via the real auto-publish flow, which always set this) never
-- got `published_at` stamped — the app-side stage-change handler wrote
-- `stage='published'` but omitted `published_at` from its UPDATE entirely.
-- This left every date-filtered query that relies on it (Mai's daily cron,
-- post-insights-cron, "last published post" throughout the app) unable to
-- see these posts at all. Fixed going forward in app.jsx/handleStageChange;
-- this backfills existing rows using scheduled_date as the best available
-- stand-in (falling back to created_at if scheduled_date is also blank).
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-backfill-published-at.sql

UPDATE posts
SET published_at = COALESCE(
  NULLIF(scheduled_date, ''),
  created_at
)
WHERE stage = 'published' AND published_at IS NULL;
