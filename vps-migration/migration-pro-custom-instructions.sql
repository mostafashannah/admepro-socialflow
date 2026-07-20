-- Company-wide "Pro Skills" custom instructions, edited from
-- Settings -> AI & Tokens, injected into Pro's system prompt for every user.
-- Run once:
--   sudo mysql socialflow < migration-pro-custom-instructions.sql

ALTER TABLE app_settings
  ADD COLUMN pro_custom_instructions TEXT;
