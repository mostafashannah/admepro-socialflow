-- Run once against the live database.
-- Fixes new projects silently failing to save (and disappearing on refresh):
-- the frontend always sends project_type, but the projects table never had
-- this column, so every INSERT into projects was rejected by MySQL.
-- (mysql-schema.sql already has this column for fresh installs.)
ALTER TABLE projects ADD COLUMN project_type VARCHAR(50) DEFAULT 'social_calendar';
