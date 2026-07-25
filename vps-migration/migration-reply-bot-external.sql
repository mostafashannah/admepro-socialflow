-- Some clients run their own reply bot on their own system, subscribed to
-- the same connected Meta Page/Instagram account via their own app. This
-- flag lets SocialFlow keep receiving and displaying those messages in the
-- unified Inbox (for visibility/reporting) while guaranteeing our own
-- reply bot NEVER sends a reply for that client — the client's own system
-- handles replying, we're view-only. Distinct from `enabled=0` (which just
-- means "our bot is temporarily off") so the UI can label it clearly and
-- an account manager doesn't accidentally re-enable auto-replies and cause
-- two bots to answer the same customer.
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-reply-bot-external.sql

ALTER TABLE reply_bot_settings ADD COLUMN external_bot TINYINT(1) DEFAULT 0;
