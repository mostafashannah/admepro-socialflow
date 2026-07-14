-- Supports the "complete your application" email imap-recruitment-cron.php
-- sends when an email-captured application is missing required fields
-- (CV, phone, salary expectations, join date, etc). The token identifies
-- the application on the public completion page without needing a login.
-- Run once:
--   sudo mysql socialflow < migration-recruitment-completion-token.sql

ALTER TABLE job_applications
  ADD COLUMN completion_token VARCHAR(64) UNIQUE;
