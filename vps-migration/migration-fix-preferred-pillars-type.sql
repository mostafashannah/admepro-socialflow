-- preferred_pillars is a free-text field in the app (a plain "Educational,
-- Promotional, Behind-the-scenes" text input), but the column was created
-- as JSON — MySQL's JSON validation rejects a bare empty string (it must
-- be valid JSON: a quoted string or null), so saving with this field
-- empty failed the ENTIRE Scheduling save with a 500 error:
--   SQLSTATE[22032]: Invalid JSON text: "The document is empty."
-- Changing it to TEXT matches how it's actually used. Run once:
--   mysql -u socialflow_app -p socialflow < migration-fix-preferred-pillars-type.sql

ALTER TABLE client_intelligence MODIFY COLUMN preferred_pillars TEXT;
