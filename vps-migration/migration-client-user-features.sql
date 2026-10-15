-- Per-user portal section access, set by the account's portal admin
-- (JSON like {"inbox":false}); NULL = everything the account allows.
ALTER TABLE client_users ADD COLUMN portal_features TEXT NULL;
