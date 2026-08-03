CREATE TABLE IF NOT EXISTS leave_credit_events (
  id CHAR(36) NOT NULL PRIMARY KEY,
  team_member_id CHAR(36) NOT NULL,
  member_name VARCHAR(255) DEFAULT NULL,
  credit_type ENUM('personal_leave_hours','wfh_days','vacation_days') NOT NULL,
  amount DECIMAL(6,2) NOT NULL,
  month_key CHAR(7) NOT NULL,
  work_date DATE DEFAULT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_member_month (team_member_id, month_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
