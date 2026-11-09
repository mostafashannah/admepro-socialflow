-- Once-per-day guard for the task start / 5-minutes-left emails sent by auto-timer-cron.php.
CREATE TABLE IF NOT EXISTS task_alert_log (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  post_id VARCHAR(64) NOT NULL,
  user_email VARCHAR(255) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  alert_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alert (post_id, user_email, kind, alert_date)
) ENGINE=InnoDB;
