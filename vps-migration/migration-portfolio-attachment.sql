-- Adds an optional portfolio file upload (separate from the existing
-- portfolio_url link field) to job applications. Run once:
--   sudo mysql socialflow < migration-portfolio-attachment.sql

ALTER TABLE job_applications ADD COLUMN portfolio_attachment_url TEXT;
