-- An admin deleting a job_applications row doesn't stop the recruitment
-- cron from re-capturing the same email next run — the email_message_id
-- UNIQUE constraint only protects rows that still exist. This table is a
-- permanent tombstone: once a message id lands here, the cron skips it
-- forever, even after the original application row is gone.
CREATE TABLE IF NOT EXISTS deleted_email_applications (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  email_message_id VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;
