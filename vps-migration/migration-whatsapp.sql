-- Run once against the live database to add WhatsApp support.
-- (mysql-schema.sql already has this column for fresh installs.)
ALTER TABLE team_members ADD COLUMN whatsapp_number TEXT;
