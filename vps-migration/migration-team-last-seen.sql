-- Adds last_seen to team_members, used for real "who's online now" presence
-- tracking on the System Log page (a 60s heartbeat from each signed-in
-- member updates this column; a member is considered "live" if seen within
-- the last 3 minutes). Requires ALTER privileges — run via:
--   sudo mysql socialflow < migration-team-last-seen.sql

ALTER TABLE team_members ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL;
