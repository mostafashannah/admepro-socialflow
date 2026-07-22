-- Adds candidate task submission (via link or file upload, plus an
-- optional explanation note) to the "Send Task" feature. Run once:
--   mysql -u socialflow_app -p socialflow < migration-application-task-submission.sql

ALTER TABLE job_applications
  ADD COLUMN task_token VARCHAR(64),
  ADD COLUMN task_submission_url TEXT,
  ADD COLUMN task_submission_link TEXT,
  ADD COLUMN task_submission_note TEXT,
  ADD COLUMN task_submitted_at TIMESTAMP NULL;
