-- New "Website" field on Add Client, used by Mai to auto-research a brand-new
-- client (products/services, tone, location, competitors, pricing
-- positioning, target audience) and seed Client Knowledge/Memory with a
-- starting point for the account team to verify — see maiResearchNewClient()
-- in app.jsx.
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-client-website.sql

ALTER TABLE clients ADD COLUMN website TEXT;
