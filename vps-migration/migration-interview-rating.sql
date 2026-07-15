-- Post-interview 1-5 star rating, set by staff after interviewing a
-- candidate. Factored into the overall ranking (applicationRank() in
-- app.jsx) alongside the AI CV fit score.
ALTER TABLE job_applications ADD COLUMN interview_rating TINYINT NULL;
