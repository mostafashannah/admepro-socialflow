-- Run once against the live database. Gives Pro (WhatsApp assistant) short-term
-- conversation memory — previously each incoming message was answered with zero
-- awareness of prior messages in the same chat, which caused multi-step flows
-- (like "add a transaction" where amount/description arrive in separate
-- messages) to loop forever re-asking for info the user already gave.

CREATE TABLE IF NOT EXISTS pro_messages (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  phone VARCHAR(30) NOT NULL,
  role VARCHAR(10) NOT NULL, -- 'user' | 'assistant'
  content MEDIUMTEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE INDEX idx_pro_messages_phone ON pro_messages(phone, created_at);
