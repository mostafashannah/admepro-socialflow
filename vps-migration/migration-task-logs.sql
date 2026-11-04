-- Task log: one row per person per phase per round (see logTaskPhase in app.jsx).
CREATE TABLE IF NOT EXISTS task_logs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  post_id VARCHAR(36) NOT NULL, post_title TEXT, client_name TEXT,
  phase VARCHAR(50), round INT DEFAULT 1,
  assignee_email VARCHAR(255), assignee_name TEXT, assigned_by TEXT,
  assigned_at VARCHAR(40) NULL, completed_at VARCHAR(40) NULL,
  duration_mins INT NULL, outcome TEXT
) ENGINE=InnoDB;
CREATE INDEX idx_task_logs_post_id ON task_logs(post_id);
