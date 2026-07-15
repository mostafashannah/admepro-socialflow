-- Keeps a record of previously-confirmed interview times when staff
-- reschedules — the old confirmed slot never gets silently overwritten,
-- it moves here so there's a trail of what changed.
ALTER TABLE job_applications ADD COLUMN interview_slot_history TEXT NULL;
