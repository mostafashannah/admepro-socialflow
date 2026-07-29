-- The date the call/meeting actually happened, separate from created_at
-- (when the report was submitted/logged) — a report is often logged a day
-- or more after the actual meeting.
ALTER TABLE contact_reports ADD COLUMN meeting_date DATE DEFAULT NULL;
