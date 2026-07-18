-- external_post_id/published_at/publish_error/publish_attempts were in the
-- original mysql-schema.sql but never actually got created on this server's
-- posts table — post-insights-cron.php and auto-publish.php both need them.
ALTER TABLE posts ADD COLUMN external_post_id TEXT;
ALTER TABLE posts ADD COLUMN published_at TIMESTAMP NULL;
ALTER TABLE posts ADD COLUMN publish_error TEXT;
ALTER TABLE posts ADD COLUMN publish_attempts DECIMAL(4,0) DEFAULT 0;
