-- Lets a client's contact person's job title be saved and reused (e.g. on
-- the Contact Report attendee picker) instead of always showing a generic
-- "Client Contact" label.
ALTER TABLE clients ADD COLUMN contact_title VARCHAR(120) DEFAULT NULL;
