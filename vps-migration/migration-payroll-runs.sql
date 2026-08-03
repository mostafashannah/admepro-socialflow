CREATE TABLE IF NOT EXISTS payroll_runs (
  id CHAR(36) NOT NULL PRIMARY KEY,
  team_member_id CHAR(36) NOT NULL,
  member_name VARCHAR(255) DEFAULT NULL,
  salary_month CHAR(7) NOT NULL, -- 'YYYY-MM' — the month being paid for
  base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  vacation_overage_days DECIMAL(6,2) NOT NULL DEFAULT 0,
  deduction_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | approved | rejected
  decided_by TEXT,
  decided_at DATETIME DEFAULT NULL,
  expense_id CHAR(36) DEFAULT NULL, -- the outstanding expense created on approval
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_member_month (team_member_id, salary_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
