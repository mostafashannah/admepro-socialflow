-- Run once against the live database.
-- Adds a tone-level setting (slang | friendly | formal) to the reply bot,
-- so each client's auto-replies can be locked to a specific register.
-- (mysql-schema.sql already has this column for fresh installs.)
ALTER TABLE reply_bot_settings ADD COLUMN tone VARCHAR(20) DEFAULT 'friendly';
