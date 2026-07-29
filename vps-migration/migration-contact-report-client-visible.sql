-- Tracks when a contact report actually became visible to the client on
-- their portal — immediately at creation if that client's auto-email
-- setting is on, or the moment an account manager manually sends it
-- otherwise. NULL means never shown to the client.
ALTER TABLE contact_reports ADD COLUMN client_visible_at DATETIME NULL;
