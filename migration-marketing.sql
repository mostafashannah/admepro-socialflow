-- Marketing (newsletters + WhatsApp campaigns) and the Sales agent (run once as the MySQL admin):
--   mysql -u root -p socialflow < /var/www/socialflow/migration-marketing.sql
CREATE TABLE IF NOT EXISTS mkt_contacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NULL, phone VARCHAR(32) NULL, name VARCHAR(255) NULL, company VARCHAR(255) NULL,
  lang VARCHAR(5) NULL, tags VARCHAR(500) NULL, source VARCHAR(40) NULL,
  consent VARCHAR(20) NOT NULL DEFAULT 'lead',          -- opt_in | lead | none
  consent_at DATETIME NULL, consent_note VARCHAR(255) NULL,
  wa_opt_in TINYINT NOT NULL DEFAULT 0,                 -- agreed to WhatsApp marketing
  unsub_email TINYINT NOT NULL DEFAULT 0, unsub_wa TINYINT NOT NULL DEFAULT 0, bounced TINYINT NOT NULL DEFAULT 0,
  lead_id VARCHAR(64) NULL, client_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NULL,
  UNIQUE KEY uq_email (email), KEY idx_phone (phone), KEY idx_source (source)
);
CREATE TABLE IF NOT EXISTS mkt_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  channel VARCHAR(10) NOT NULL,                         -- email | whatsapp
  name VARCHAR(255) NOT NULL, subject VARCHAR(255) NULL, preheader VARCHAR(255) NULL,
  blocks MEDIUMTEXT NULL,                               -- email content blocks (JSON)
  wa_template VARCHAR(120) NULL, wa_lang VARCHAR(10) NULL, wa_params TEXT NULL, wa_header_image VARCHAR(500) NULL,
  audience TEXT NULL,                                   -- filter (JSON)
  status VARCHAR(12) NOT NULL DEFAULT 'draft',          -- draft | scheduled | sending | sent | cancelled
  scheduled_at DATETIME NULL, started_at DATETIME NULL, finished_at DATETIME NULL,
  created_by VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
  KEY idx_status (status, scheduled_at)
);
CREATE TABLE IF NOT EXISTS mkt_sends (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL, contact_id BIGINT UNSIGNED NOT NULL, to_addr VARCHAR(255) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'queued',         -- queued | sent | failed
  provider_id VARCHAR(255) NULL, error VARCHAR(500) NULL,
  sent_at DATETIME NULL, opened_at DATETIME NULL, clicked_at DATETIME NULL, unsub_at DATETIME NULL,
  UNIQUE KEY uq_send (campaign_id, contact_id), KEY idx_queue (campaign_id, status)
);
CREATE TABLE IF NOT EXISTS sales_prospects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company VARCHAR(255) NOT NULL, domain VARCHAR(255) NULL, website VARCHAR(500) NULL,
  industry VARCHAR(120) NULL, country VARCHAR(80) NULL, city VARCHAR(120) NULL,
  score INT NULL, why_fit TEXT NULL, signals TEXT NULL, sources TEXT NULL,
  contacts MEDIUMTEXT NULL,                             -- JSON: public + verified decision makers
  outreach MEDIUMTEXT NULL,                             -- JSON: drafted emails + what was sent
  status VARCHAR(12) NOT NULL DEFAULT 'new',            -- new | approved | contacted | replied | won | rejected
  brief VARCHAR(500) NULL, lead_id VARCHAR(64) NULL,
  created_by VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
  UNIQUE KEY uq_domain (domain), KEY idx_status (status)
);

-- 1:1 sales outreach log (daily cap + history)
CREATE TABLE IF NOT EXISTS sales_sends (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prospect_id BIGINT UNSIGNED NOT NULL,
  to_email VARCHAR(255) NOT NULL,
  step TINYINT NOT NULL DEFAULT 1,
  subject VARCHAR(255) NULL,
  provider_id VARCHAR(100) NULL,
  sent_by VARCHAR(255) NULL,
  sent_at DATETIME NOT NULL,
  KEY idx_prospect (prospect_id),
  KEY idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
