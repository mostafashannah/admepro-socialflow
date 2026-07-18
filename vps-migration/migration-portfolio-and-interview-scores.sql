-- Adds a manual portfolio score (staff-entered, after reviewing the
-- candidate's portfolio) and breaks the single 1-5 star interview_rating
-- into 4 scored dimensions (dress/appearance, communication, experience,
-- creativity), each 1-5. Both now factor into the application's overall
-- ranking alongside the existing AI CV score.
ALTER TABLE job_applications ADD COLUMN portfolio_score INT NULL;
ALTER TABLE job_applications ADD COLUMN interview_dress_score INT NULL;
ALTER TABLE job_applications ADD COLUMN interview_communication_score INT NULL;
ALTER TABLE job_applications ADD COLUMN interview_experience_score INT NULL;
ALTER TABLE job_applications ADD COLUMN interview_creativity_score INT NULL;
