-- Run once against the live database to add the per-client AI reply-bot feature.
-- (mysql-schema.sql already has these for fresh installs.)

CREATE TABLE IF NOT EXISTS reply_bot_settings (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  client_id VARCHAR(36) UNIQUE NOT NULL, client_name TEXT,
  enabled TINYINT(1) DEFAULT 0,
  mode VARCHAR(20) DEFAULT 'approve', -- approve | auto
  channels JSON DEFAULT ('["instagram","messenger"]'),
  brain TEXT, -- dedicated reply-bot instructions, separate from general Client Brain
  updated_by TEXT
) ENGINE=InnoDB;
CREATE INDEX idx_reply_bot_settings_client ON reply_bot_settings(client_id);

ALTER TABLE customer_messages ADD COLUMN draft_status VARCHAR(20) DEFAULT NULL; -- pending_review | sent | dismissed (NULL = not a bot draft)
