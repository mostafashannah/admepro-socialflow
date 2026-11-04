-- Account Director check-in (report_type = 'director'): stores what Mai noted + what she flagged for the admin.
ALTER TABLE mai_report_sessions ADD COLUMN summary TEXT NULL;
