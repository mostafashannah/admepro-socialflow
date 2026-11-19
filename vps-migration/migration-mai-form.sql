-- Mai's reports as a link (no login): each report session gets a private token, the step-by-step answers, and a mode.
-- Run once as root:  mysql -u root -p socialflow < vps-migration/migration-mai-form.sql
-- Until it is run, Mai keeps using the old WhatsApp chat check-in.
ALTER TABLE mai_report_sessions
  ADD COLUMN token VARCHAR(64) NULL,
  ADD COLUMN answers MEDIUMTEXT NULL,
  ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'chat';
CREATE UNIQUE INDEX uq_mai_report_token ON mai_report_sessions (token);
