-- Meta's WhatsApp webhook can redeliver the same inbound message more than
-- once (slow ack, network retry) — without this, wa-webhook.php reprocessed
-- it every time, causing Pro to re-run tool calls like add_transaction and
-- create duplicate expense/income rows. This table lets the webhook check
-- "have I already handled this exact WhatsApp message id" before doing any
-- work, using the primary key as the uniqueness guarantee.
-- Run once:
--   sudo mysql socialflow < migration-wa-message-dedup.sql

CREATE TABLE IF NOT EXISTS wa_processed_messages (
  message_id VARCHAR(128) PRIMARY KEY,
  processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
