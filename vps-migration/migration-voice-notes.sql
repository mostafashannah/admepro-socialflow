-- Run once against the live database to support Pro transcribing WhatsApp
-- voice notes into structured contact/call reports.

CREATE TABLE IF NOT EXISTS contact_reports (
  id CHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  client_id CHAR(36) NULL,
  client_name VARCHAR(255) NULL,
  created_by_id CHAR(36) NULL,
  created_by_name VARCHAR(255) NULL,
  transcript TEXT NULL,
  summary TEXT NULL,
  key_points TEXT NULL,
  action_items TEXT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_reports_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
