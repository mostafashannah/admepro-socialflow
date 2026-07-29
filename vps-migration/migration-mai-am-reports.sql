-- Mai's twice-daily account-manager check-in reports, over WhatsApp.
-- Morning report (~12pm): did you check platforms/ad accounts, how are
-- results, answered all comms, today's meetings/submissions/lead follow-ups.
-- End-of-day report (~7pm): follow-up on the morning items, confirms
-- contact reports were logged for today's meetings. Skips Friday/Saturday.
-- Each session is one multi-turn WhatsApp conversation; `checklist` tracks
-- the scored yes/no items, `transcript` holds the full back-and-forth
-- (including the open-ended relationship-building chat, which is logged
-- but not scored). One row per account manager per report_type per day.
CREATE TABLE IF NOT EXISTS mai_report_sessions (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  account_manager_id CHAR(36) NOT NULL,
  account_manager_name TEXT,
  account_manager_email TEXT,
  report_type VARCHAR(10) NOT NULL, -- 'morning' | 'eod'
  report_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'in_progress', -- in_progress | completed | no_response
  checklist JSON DEFAULT ('{}'), -- {item_key: {label, done, note}}
  score INT NULL,
  max_score INT NULL,
  transcript JSON DEFAULT ('[]'), -- [{role, content, at}]
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  UNIQUE KEY uniq_am_report_day (account_manager_id, report_type, report_date)
) ENGINE=InnoDB;
CREATE INDEX idx_mai_report_sessions_am ON mai_report_sessions(account_manager_id);

-- Idempotency guard for the admin digest — one row per (report_type, date)
-- once the digest has been sent, so a retried/overlapping cron run never
-- double-sends it.
CREATE TABLE IF NOT EXISTS mai_report_digests (
  report_type VARCHAR(10) NOT NULL,
  report_date DATE NOT NULL,
  sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (report_type, report_date)
) ENGINE=InnoDB;

-- wa_am_reports: the account manager's own opt-in for Mai's twice-daily
-- check-in conversation. wa_am_reports_digest: the admin's opt-in for the
-- one-line summary sent after each round completes.
ALTER TABLE notification_prefs
  ADD COLUMN wa_am_reports TINYINT(1) DEFAULT 1,
  ADD COLUMN wa_am_reports_digest TINYINT(1) DEFAULT 1;
