-- Adds the missing "username" column on clients (Edit Client's "Contact
-- Username" field — displayed in the client portal instead of the company
-- name). It was already in the edit form but had nowhere to actually save,
-- since this column never existed. Run once:
--   sudo mysql socialflow < migration-client-username.sql

ALTER TABLE clients ADD COLUMN username TEXT;
