-- Interview time-slot scheduling (candidate picks or suggests a time via
-- a public link) and the post-interview Offer phase (salary during/after
-- probation, start date, title, and policy points like laptop/vacation/
-- WFH allowance) on job_applications.
ALTER TABLE job_applications
  ADD COLUMN interview_slots TEXT NULL,
  ADD COLUMN interview_scheduling_token VARCHAR(64) NULL,
  ADD COLUMN interview_selected_slot VARCHAR(60) NULL,
  ADD COLUMN interview_candidate_note TEXT NULL,
  ADD COLUMN interview_confirmed_slot VARCHAR(60) NULL,
  ADD COLUMN offer_title VARCHAR(150) NULL,
  ADD COLUMN offer_salary VARCHAR(50) NULL,
  ADD COLUMN offer_probation_months INT NULL,
  ADD COLUMN offer_post_probation_salary VARCHAR(50) NULL,
  ADD COLUMN offer_start_date DATE NULL,
  ADD COLUMN offer_laptop_provided VARCHAR(10) NULL,
  ADD COLUMN offer_vacation_days_annual INT NULL,
  ADD COLUMN offer_wfh_days_monthly INT NULL,
  ADD COLUMN offer_notes TEXT NULL,
  ADD COLUMN offer_sent_at DATETIME NULL;
CREATE INDEX idx_job_applications_interview_token ON job_applications(interview_scheduling_token);
