-- Adds a short free-text "brief" field to job openings, used as the seed
-- for the AI-generated Description/Requirements. Run once:
--   sudo mysql socialflow < migration-job-opening-brief.sql

ALTER TABLE job_openings ADD COLUMN brief TEXT;
