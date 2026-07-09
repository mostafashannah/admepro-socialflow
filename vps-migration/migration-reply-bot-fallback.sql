-- Run once against the live database to let the reply bot send a fallback
-- message (instead of staying silent) whenever Claude can't confidently
-- answer and would otherwise reply NEEDS_HUMAN.
ALTER TABLE reply_bot_settings
  ADD COLUMN fallback_message TEXT NULL;
