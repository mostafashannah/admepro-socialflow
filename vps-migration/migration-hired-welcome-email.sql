ALTER TABLE job_applications ADD COLUMN hired_welcome_email_sent_at DATETIME NULL;

-- Hiring onboarding: a token-based public link (like completion_token /
-- interview_scheduling_token / offer_token) where a freshly-hired candidate
-- uploads their ID/photo and accepts the company policy before "Make Team
-- Member" can be used on their application.
ALTER TABLE job_applications ADD COLUMN onboarding_token CHAR(64) NULL;
ALTER TABLE job_applications ADD COLUMN onboarding_id_front_url TEXT NULL;
ALTER TABLE job_applications ADD COLUMN onboarding_id_back_url TEXT NULL;
ALTER TABLE job_applications ADD COLUMN onboarding_photo_url TEXT NULL;
ALTER TABLE job_applications ADD COLUMN onboarding_fish_url TEXT NULL;
ALTER TABLE job_applications ADD COLUMN onboarding_certificate_url TEXT NULL;
ALTER TABLE job_applications ADD COLUMN onboarding_social_insurance_no VARCHAR(50) NULL;
ALTER TABLE job_applications ADD COLUMN onboarding_accepted_at DATETIME NULL;
ALTER TABLE job_applications ADD COLUMN onboarding_completed_at DATETIME NULL;
CREATE INDEX idx_job_applications_onboarding_token ON job_applications(onboarding_token);
