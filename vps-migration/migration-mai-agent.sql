-- Mai — AI Account Executive: daily per-client posting-cadence check,
-- performance analysis, and client-memory prioritization.
-- `priority` lets the app sort a client's memory with the most important
-- facts first (set by Mai's daily curation pass) instead of just
-- insertion order.
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-mai-agent.sql

ALTER TABLE client_memory ADD COLUMN priority INT DEFAULT 0;
