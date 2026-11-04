-- Which sections each person wants in the Daily Digest email (JSON, e.g. {"tasks":true,"finance":false,"recruitment":true}).
-- NULL = every section that person is allowed to see.
ALTER TABLE notification_prefs ADD COLUMN digest_sections TEXT NULL;
