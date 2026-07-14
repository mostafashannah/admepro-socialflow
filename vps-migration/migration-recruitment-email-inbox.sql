-- Supports imap-recruitment-cron.php, which polls a mailbox (e.g.
-- hr@admepro.com) for application emails and turns them into
-- job_applications rows automatically.
-- Run once:
--   sudo mysql socialflow < migration-recruitment-email-inbox.sql

ALTER TABLE job_applications
  ADD COLUMN source VARCHAR(10) DEFAULT 'web',
  ADD COLUMN email_message_id VARCHAR(255) UNIQUE;
