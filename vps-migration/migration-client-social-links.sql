-- Public social profile URLs (Instagram/Facebook/TikTok/LinkedIn) collected
-- on Add Client — separate from `integrations` (real connected API
-- credentials for publishing). These are just links Mai can look at directly
-- during her new-client research (maiResearchNewClient() in app.jsx) to read
-- actual tone/content/audience straight from the client's own public pages,
-- not only their website. Stored as one JSON object:
--   {"instagram":"https://...","facebook":"https://...","tiktok":"https://...","linkedin":"https://..."}
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-client-social-links.sql

ALTER TABLE clients ADD COLUMN social_links TEXT;
