-- Welcome emails drafted by the sales agent, reviewed by the team, then sent and tracked.
-- Run once as root:  mysql -u root -p socialflow < migration-sales-welcome.sql
CREATE TABLE IF NOT EXISTS sales_welcome (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prospect_id BIGINT UNSIGNED NOT NULL,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(200) NULL,
  company VARCHAR(200) NULL,
  market VARCHAR(8) NOT NULL DEFAULT 'egypt',          -- gulf | egypt
  subject VARCHAR(255) NOT NULL,
  personal_line TEXT NULL,                              -- the one personalised sentence (editable in review)
  status VARCHAR(16) NOT NULL DEFAULT 'pending',        -- pending | approved(sent) | rejected | failed
  provider_id VARCHAR(100) NULL,
  reviewed_by VARCHAR(255) NULL, reviewed_at DATETIME NULL,
  sent_at DATETIME NULL, error VARCHAR(400) NULL,
  opened_at DATETIME NULL, open_count INT NOT NULL DEFAULT 0,
  clicked_at DATETIME NULL, click_count INT NOT NULL DEFAULT 0, last_click VARCHAR(40) NULL,
  replied_at DATETIME NULL, feedback VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_prospect_email (prospect_id, to_email),
  KEY idx_status (status), KEY idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
