-- Lets a team member's profile hold extra documents beyond the fixed
-- ID Photo Front/Back (e.g. a signed contract copy, a certificate, a
-- visa). Stored as a JSON array of {label, url}. Run once:
--   mysql -u socialflow_app -p socialflow < migration-team-extra-documents.sql

ALTER TABLE team_members
  ADD COLUMN extra_documents TEXT;
