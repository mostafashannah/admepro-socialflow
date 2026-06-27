-- Run once against the live database to let the reply-bot handle public
-- post comments (Facebook + Instagram) in addition to DMs.
-- (mysql-schema.sql already has this for fresh installs.)

ALTER TABLE customer_messages ADD COLUMN external_id VARCHAR(64) DEFAULT NULL; -- Graph API comment_id, used to post the public reply (NULL for DMs)
