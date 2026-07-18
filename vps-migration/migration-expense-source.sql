-- Tracks where a transaction was recorded from — the app's own Add
-- Transaction form vs WhatsApp Pro — so staff can tell at a glance whether
-- a record (and its exact wording, e.g. client payment descriptions) came
-- from a person typing carefully in the app or from a quick WhatsApp
-- message that might not match existing naming exactly.
ALTER TABLE expenses ADD COLUMN source VARCHAR(20) NULL DEFAULT 'app';
