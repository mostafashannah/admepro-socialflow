-- Lets a client's own reply-bot system connect through SocialFlow instead of
-- needing its own separate Meta app/webhook subscription:
--   - external_webhook_url: we POST every inbound message for this client to
--     this URL (signed with external_api_key) so their bot can process it.
--   - external_api_key: per-client secret, generated once, shared with the
--     client's dev team. Used both to sign our outbound forward POST and to
--     authenticate their POSTs back to external-bot-reply.php (the endpoint
--     that actually sends the reply via our connected Meta credentials,
--     which we never hand out to them directly).
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-external-bot-relay.sql

ALTER TABLE reply_bot_settings ADD COLUMN external_webhook_url TEXT;
ALTER TABLE reply_bot_settings ADD COLUMN external_api_key VARCHAR(64);
