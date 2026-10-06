-- Hires with a future start date are created inactive and flagged
-- pending_start; activate-members-cron.php flips them to active on the
-- start date. user_invitations.start_date carries the date (from the
-- offer or the invite form) through to the team_members row.
ALTER TABLE user_invitations ADD COLUMN start_date DATE NULL;
ALTER TABLE team_members ADD COLUMN pending_start TINYINT(1) NOT NULL DEFAULT 0;
