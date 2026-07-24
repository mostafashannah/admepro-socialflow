-- Contact Reports: capture meeting type (call/meeting), location
-- (online/physical + where), and attendees with their titles — so the
-- report is complete enough to email/export, not just a summary.
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-contact-report-details.sql

ALTER TABLE contact_reports ADD COLUMN meeting_type VARCHAR(20);
ALTER TABLE contact_reports ADD COLUMN location_type VARCHAR(20);
ALTER TABLE contact_reports ADD COLUMN location TEXT;
ALTER TABLE contact_reports ADD COLUMN attendees TEXT;
ALTER TABLE contact_reports ADD COLUMN voice_recording_url TEXT;
