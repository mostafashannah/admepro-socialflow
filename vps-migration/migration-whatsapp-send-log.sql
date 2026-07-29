-- Makes WhatsApp send failures (e.g. Meta rejecting a free-form text
-- message sent outside the 24h customer-service window) visible instead of
-- only going to the PHP error log, which nobody checks day to day.
CREATE TABLE IF NOT EXISTS whatsapp_send_log (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  to_number VARCHAR(30),
  body_preview TEXT,
  status VARCHAR(20) NOT NULL, -- 'sent' | 'failed'
  http_status INT NULL,
  error_message TEXT NULL
) ENGINE=InnoDB;
CREATE INDEX idx_whatsapp_send_log_status ON whatsapp_send_log(status, created_at);
