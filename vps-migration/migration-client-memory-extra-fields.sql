-- app.jsx's upsertClientMemory() has always sent source/confidence/is_private/
-- created_by alongside key/value/type, but the base schema (mysql-schema.sql)
-- never had these columns. api.php's INSERT is built dynamically from
-- whatever keys are in the request body with no column filtering, so on any
-- install that only ran the base schema, EVERY ClientMemory save has been
-- silently failing outright (the whole INSERT errors on the first unknown
-- column). Run once — safe no-op if these were already added by hand:
--   sudo mysql socialflow < migration-client-memory-extra-fields.sql

ALTER TABLE client_memory
  ADD COLUMN IF NOT EXISTS source TEXT,
  ADD COLUMN IF NOT EXISTS confidence DECIMAL(4,2),
  ADD COLUMN IF NOT EXISTS is_private TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS created_by TEXT;
