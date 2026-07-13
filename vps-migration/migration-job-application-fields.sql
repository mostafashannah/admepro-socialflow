-- Adds Current Salary / Expected Salary / Available Join Date / Open to Test
-- Task fields to the public job-application form. Run once:
--   sudo mysql socialflow < migration-job-application-fields.sql

ALTER TABLE job_applications
  ADD COLUMN current_salary TEXT,
  ADD COLUMN expected_salary TEXT,
  ADD COLUMN available_start_date TEXT,
  ADD COLUMN open_to_task VARCHAR(10);
