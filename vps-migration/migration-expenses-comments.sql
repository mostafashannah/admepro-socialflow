-- Run once against the live database, after migration-expenses-type.sql.
-- Adds a comment thread to each Finance transaction (stored as a JSON array
-- of {text, by, at} objects) for the new transaction detail page.

ALTER TABLE expenses ADD COLUMN comments TEXT NULL DEFAULT NULL;
