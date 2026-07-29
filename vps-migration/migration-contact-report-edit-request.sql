-- Lets a client leave comments on a contact report from their portal —
-- a simple running thread, not an edit-request workflow. Staff see them
-- inline on the internal Contact Reports tab.
ALTER TABLE contact_reports ADD COLUMN client_comments JSON DEFAULT ('[]');
