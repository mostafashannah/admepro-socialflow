-- Adds attachment_url to customer_messages — the image URL a customer sent
-- in a DM (Meta-hosted, temporary link), so it can be shown in the inbox
-- and forwarded to a client's external reply-bot alongside the message
-- text. Run once:
--   mysql -u root -p socialflow < migration-customer-message-attachment.sql

ALTER TABLE customer_messages
  ADD COLUMN attachment_url TEXT;
