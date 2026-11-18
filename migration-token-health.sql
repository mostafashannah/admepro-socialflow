-- Run once as the MySQL admin:
--   mysql -u root -p socialflow < /var/www/socialflow/migration-token-health.sql
CREATE TABLE IF NOT EXISTS token_health (
  integration_id CHAR(36) NOT NULL PRIMARY KEY,
  checked_at DATETIME NOT NULL,
  state VARCHAR(20) NOT NULL,
  expires_at DATETIME NULL,
  note VARCHAR(255) NULL,
  last_alert_at DATETIME NULL
);
