-- Adds assigned_to_extra to posts — additional (non-primary) assignees a
-- task can be given, alongside the existing single `assigned_to` field.
-- Stored as a JSON array of emails. Run once:
--   mysql -u root -p socialflow < migration-post-assigned-extra.sql

ALTER TABLE posts
  ADD COLUMN assigned_to_extra JSON DEFAULT ('[]');
