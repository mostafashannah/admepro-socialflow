-- Stores a local copy of recruitment mailbox messages (Inbox + Sent) so
-- the mailbox viewer reads from our own database instead of hitting IMAP
-- on every page load. recruitment-mailbox.php syncs new messages in from
-- IMAP periodically and prunes rows older than the configured retention
-- period (Recruitment > Email Settings > Keep Emails For).
CREATE TABLE IF NOT EXISTS recruitment_mailbox_messages (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  box VARCHAR(10) NOT NULL,
  message_id VARCHAR(500) NOT NULL,
  thread_key VARCHAR(500) NOT NULL,
  subject TEXT,
  from_email VARCHAR(255),
  from_name VARCHAR(255),
  to_emails TEXT,
  message_date DATETIME,
  body MEDIUMTEXT,
  has_attachments TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_box_msgid (box, message_id(255))
) ENGINE=InnoDB;
CREATE INDEX idx_recruitment_mailbox_thread ON recruitment_mailbox_messages(thread_key(255));
CREATE INDEX idx_recruitment_mailbox_date ON recruitment_mailbox_messages(message_date);
