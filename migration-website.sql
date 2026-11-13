-- Website tab: visitor analytics for admepro.com (run once as the MySQL admin):
--   mysql -u root -p socialflow < /var/www/socialflow/migration-website.sql
CREATE TABLE IF NOT EXISTS site_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL,
  day DATE NOT NULL,
  event VARCHAR(20) NOT NULL DEFAULT 'pageview',
  path VARCHAR(255) NOT NULL,
  title VARCHAR(255) NULL,
  ref_host VARCHAR(255) NULL,
  utm_source VARCHAR(100) NULL,
  utm_medium VARCHAR(100) NULL,
  utm_campaign VARCHAR(100) NULL,
  country CHAR(2) NULL,
  lang VARCHAR(8) NULL,
  device VARCHAR(10) NULL,
  browser VARCHAR(20) NULL,
  visitor CHAR(16) NOT NULL,
  secs INT NULL,
  KEY idx_day (day), KEY idx_ts (ts), KEY idx_path (path(100)), KEY idx_visitor_day (visitor, day)
);
