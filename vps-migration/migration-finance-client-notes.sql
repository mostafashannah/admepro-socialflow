-- Run once against the live database. Lets the Finance > Clients detail page
-- store a phone number / notes for a payment-history client (these clients
-- are derived from transaction descriptions, not the Clients/CRM module, so
-- they need their own small profile table).

CREATE TABLE IF NOT EXISTS finance_client_notes (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  client_name VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(50) NULL,
  notes TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
