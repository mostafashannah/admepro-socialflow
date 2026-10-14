-- Presence heartbeat for client portal sessions (same idea as
-- team_members.last_seen): client_users for named client logins, clients
-- for the legacy whole-client portal login.
ALTER TABLE client_users ADD COLUMN last_seen DATETIME NULL;
ALTER TABLE clients ADD COLUMN last_seen DATETIME NULL;
