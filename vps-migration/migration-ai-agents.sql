-- Team-wide config for the AI agent crew (Settings -> AI Agents):
-- JSON map of agent_id -> {model, skills}, e.g.
--   {"content_creator":{"model":"claude-sonnet-4-6","skills":"..."}}
-- Run once:
--   sudo mysql socialflow < migration-ai-agents.sql

ALTER TABLE app_settings
  ADD COLUMN ai_agents JSON DEFAULT ('{}');
