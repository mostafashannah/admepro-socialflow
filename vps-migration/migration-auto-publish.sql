-- Run once against the already-deployed MySQL database to support
-- auto-publish.php (Facebook/Instagram scheduled auto-publishing).
-- Safe to re-run: each ALTER is wrapped to ignore "column already exists".

ALTER TABLE posts ADD COLUMN published_at TIMESTAMP NULL;
ALTER TABLE posts ADD COLUMN external_post_id TEXT;
ALTER TABLE posts ADD COLUMN publish_error TEXT;
ALTER TABLE posts ADD COLUMN publish_attempts DECIMAL(4,0) DEFAULT 0;

-- The Facebook/Instagram integration wizard lets you pick which client a
-- Page belongs to, but the integrations table never had columns to store
-- it — every integration silently matched every client. Adds the missing
-- columns so client-scoped publishing/insights actually work.
ALTER TABLE integrations ADD COLUMN client_id VARCHAR(36);
ALTER TABLE integrations ADD COLUMN client_name TEXT;
