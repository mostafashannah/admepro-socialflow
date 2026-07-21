-- Tracks a post's real revision count (times it was moved back to an
-- earlier pipeline stage) and whether it was ever rejected, so a real
-- performance_logs row can be written on publish instead of relying on the
-- seeded demo data. Run once:
--   mysql -u root -p socialflow < migration-post-revision-tracking.sql

ALTER TABLE posts
  ADD COLUMN revision_count INT DEFAULT 0,
  ADD COLUMN was_rejected TINYINT(1) DEFAULT 0;
