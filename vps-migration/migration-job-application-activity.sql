-- Activity log for job_applications — who did what and when: status
-- changes, reassignments, interview ratings, emails sent (from the
-- recruitment cron), and candidate-submitted forms.
CREATE TABLE IF NOT EXISTS job_application_activity (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  application_id VARCHAR(36) NOT NULL,
  action TEXT NOT NULL,
  actor_name TEXT
) ENGINE=InnoDB;
CREATE INDEX idx_job_application_activity_app_id ON job_application_activity(application_id);
