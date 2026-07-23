-- Lets staff hide specific client-portal nav sections (Tasks, Inbox,
-- Insights, Assets, Leads, Subscriptions) per client, from the client's
-- own Settings > Features sub-tab. Stored as a JSON object,
-- {"key": false} meaning hidden; missing/true means shown, so existing
-- clients default to everything visible with no backfill needed. Run once:
--   mysql -u socialflow_app -p socialflow < migration-client-portal-features.sql

ALTER TABLE clients
  ADD COLUMN portal_features TEXT;
