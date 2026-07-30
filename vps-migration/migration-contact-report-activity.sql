-- Run once against the live database to support a detailed per-report
-- activity log on Contact Reports (created, edited, emailed — with
-- recipients — PDF exported, client commented), shown under each report
-- instead of a single summarized "Sent to client" line.

CREATE TABLE IF NOT EXISTS contact_report_activity (
  id CHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  report_id CHAR(36) NOT NULL,
  action VARCHAR(30) NOT NULL,
  actor_name VARCHAR(255) NULL,
  detail TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_report_activity_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
