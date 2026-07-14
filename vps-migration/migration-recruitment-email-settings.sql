-- Lets Recruitment > Email Settings (app.jsx) control the IMAP mailbox
-- and applicant confirmation email from the UI instead of editing
-- config.php on the server. Falls back to the RECRUITMENT_IMAP_*
-- constants in config.php when this JSON is empty.
-- Run once:
--   sudo mysql socialflow < migration-recruitment-email-settings.sql

ALTER TABLE app_settings
  ADD COLUMN recruitment_email_settings JSON DEFAULT ('{}');
