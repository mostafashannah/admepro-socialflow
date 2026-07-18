-- Per-post performance metrics, refreshed periodically by post-insights-cron.php,
-- so a "Best Performing Posts" ranking can be shown to staff and clients.
-- external_post_id already existed in the original schema but was never
-- populated — it's set now at publish time (the ID Meta returns).
ALTER TABLE posts
  ADD COLUMN insight_likes INT NULL,
  ADD COLUMN insight_comments INT NULL,
  ADD COLUMN insight_shares INT NULL,
  ADD COLUMN insight_reach INT NULL,
  ADD COLUMN insight_fetched_at TIMESTAMP NULL;
