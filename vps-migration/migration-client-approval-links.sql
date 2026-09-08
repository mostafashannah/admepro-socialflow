-- Client-facing approval links: a 24h-expiring public link/QR code an AM
-- generates on a task sitting in Client Approval, sent to the client (e.g.
-- via WhatsApp) so they can preview the media/caption/hashtags/publish date
-- and approve or leave a comment without needing a SocialFlow login.

ALTER TABLE clients ADD COLUMN whatsapp_group_link TEXT;

CREATE TABLE IF NOT EXISTS client_approval_links (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  post_id CHAR(36) NOT NULL,
  client_id CHAR(36),
  token VARCHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | approved | commented
  comment TEXT,
  responded_at TIMESTAMP NULL,
  created_by TEXT,
  UNIQUE KEY uq_cal_token (token),
  KEY idx_cal_post (post_id)
) ENGINE=InnoDB;
