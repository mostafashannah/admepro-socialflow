-- Optional extra links a candidate can provide beyond the generic
-- portfolio_url/portfolio_attachment_url/linkedin_url fields.
ALTER TABLE job_applications ADD COLUMN behance_url TEXT;
ALTER TABLE job_applications ADD COLUMN canva_url TEXT;
ALTER TABLE job_applications ADD COLUMN instagram_url TEXT;
ALTER TABLE job_applications ADD COLUMN video_url TEXT;
