-- Admin switch + recipient list for Mai's daily client check (mai-daily-report-cron.php).
-- Settings > Mai Daily Report. One row, upserted by the app. Starts OFF.
CREATE TABLE IF NOT EXISTS mai_report_settings (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enabled TINYINT(1) DEFAULT 0,
  recipients TEXT
) ENGINE=InnoDB;
INSERT INTO mai_report_settings (enabled, recipients)
  SELECT 0, '[]' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM mai_report_settings);
