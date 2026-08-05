-- Add Title column to client_users — the Add/Edit Client User modals now
-- collect a job title (e.g. "Marketing Manager") alongside the existing
-- mobile/phone field, since contact reports display it for client
-- attendees.
ALTER TABLE client_users ADD COLUMN IF NOT EXISTS title TEXT;
