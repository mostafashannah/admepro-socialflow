-- Repeating tasks created from the Timeline (menu > Repeated Task). recurring-tasks-cron.php creates each day's task.
CREATE TABLE IF NOT EXISTS recurring_tasks (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  project_id VARCHAR(64),
  client_id VARCHAR(64),
  client_name VARCHAR(255),
  assigned_to VARCHAR(255),
  stage VARCHAR(40) DEFAULT 'design',
  priority VARCHAR(16) DEFAULT 'medium',
  post_type VARCHAR(64) DEFAULT 'design',
  due_time VARCHAR(5),
  estimated_minutes INT DEFAULT 60,
  rule_type VARCHAR(16) DEFAULT 'daily',   -- daily | weekly | interval | monthly
  rule_days TEXT,                          -- JSON array of weekdays (0=Sun..6=Sat) for weekly
  rule_interval INT DEFAULT 1,             -- every N days for interval
  start_date DATE NOT NULL,
  end_date DATE NULL,
  last_generated_date DATE NULL,
  active TINYINT(1) DEFAULT 1,
  created_by VARCHAR(255)
) ENGINE=InnoDB;
