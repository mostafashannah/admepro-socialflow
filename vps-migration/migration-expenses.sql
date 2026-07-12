-- Run once against the live database to add the Finance page's expense tracking.
-- (mysql-schema.sql should also get this block added for fresh installs.)

CREATE TABLE IF NOT EXISTS expenses (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  category VARCHAR(30) NOT NULL DEFAULT 'other', -- salaries | tools | rent | ads | freelancers | other
  description TEXT,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency VARCHAR(10) DEFAULT 'EGP',
  date DATE NOT NULL,
  created_by TEXT
) ENGINE=InnoDB;
CREATE INDEX idx_expenses_date ON expenses(date);
