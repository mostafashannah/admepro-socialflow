-- Persists AI provider billing/quota outage state (set by
-- ai-outage-notify.php on a billing-shaped failure, cleared automatically
-- the next time that provider succeeds) so the Dashboard can show an
-- in-app warning banner with a direct "Add credit" link, not just a
-- WhatsApp ping someone might miss. JSON shape:
-- {"Anthropic": {"detected_at": "...", "message": "..."}, "OpenAI": {...}}
ALTER TABLE app_settings
  ADD COLUMN ai_outage_status TEXT NULL;
