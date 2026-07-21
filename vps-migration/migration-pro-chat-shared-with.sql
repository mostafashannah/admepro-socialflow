-- Tracks who a Pro chat session has been shared with (on the sender's own
-- copy), so the UI can show a "shared with" indicator and let the sender
-- unshare later. Sharing itself still works by copying the chat into a new
-- session row owned by the recipient (unchanged) — this column just remembers
-- that fact on the original.
--   mysql -u root -p socialflow < migration-pro-chat-shared-with.sql

ALTER TABLE pro_chat_sessions
  ADD COLUMN IF NOT EXISTS shared_with JSON DEFAULT ('[]');
