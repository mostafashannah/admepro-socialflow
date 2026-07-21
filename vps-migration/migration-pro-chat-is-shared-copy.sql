-- Marks a Pro chat session as a copy someone else shared with you (as
-- opposed to a chat you own) — used to hide the "Share" option on a shared
-- copy, since only the original owner should be able to re-share it further.
--   mysql -u root -p socialflow < migration-pro-chat-is-shared-copy.sql

ALTER TABLE pro_chat_sessions
  ADD COLUMN is_shared_copy TINYINT(1) DEFAULT 0;
