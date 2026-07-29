-- Fixes a duplicate-notification bug: imap-recruitment-cron.php dedupes new
-- applications via job_applications.email_message_id, but a "Re:" reply
-- (e.g. a candidate asking to change their confirmed interview time) never
-- creates a job_applications row, so that dedup check never sees it. The
-- cron re-fetches everything from the last N days on every run (deliberately
-- not IMAP-unseen-only, see imap-recruitment-cron.php), so the same reply
-- email got reprocessed — and re-alerted/re-WhatsApp'd to every admin — on
-- every single run until it aged out of the fetch window.
CREATE TABLE IF NOT EXISTS processed_email_messages (
  message_id_hash CHAR(64) PRIMARY KEY,
  message_id TEXT NOT NULL,
  processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
