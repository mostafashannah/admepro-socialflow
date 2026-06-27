-- Run once against the live database to add the reply-bot "don't do" hard-rules field.
ALTER TABLE reply_bot_settings ADD COLUMN dont_do TEXT DEFAULT NULL; -- hard "never do this" rules, takes priority over brain
