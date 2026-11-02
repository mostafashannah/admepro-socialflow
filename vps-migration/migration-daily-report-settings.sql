-- Admin-controlled settings for the WhatsApp Daily Report
-- (daily-report-cron.php / Settings > Daily Report). One row, upserted by the app.
CREATE TABLE IF NOT EXISTS daily_report_settings (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enabled TINYINT(1) DEFAULT 0,
  send_time VARCHAR(5) DEFAULT '19:00',
  days TEXT,
  sections TEXT,
  client_ids TEXT,
  recipients TEXT,
  window_hours INT DEFAULT 24,
  last_sent_date DATE NULL
) ENGINE=InnoDB;
