-- Adds a "send a task/test assignment" feature to job applications —
-- staff can send the candidate a written task (with optional file
-- attachments) and a due date, defaulting to 3 working days out. Run once:
--   mysql -u socialflow_app -p socialflow < migration-application-task.sql

ALTER TABLE job_applications
  ADD COLUMN task_description TEXT,
  ADD COLUMN task_attachments TEXT,
  ADD COLUMN task_due_date DATE,
  ADD COLUMN task_sent_at TIMESTAMP NULL;
