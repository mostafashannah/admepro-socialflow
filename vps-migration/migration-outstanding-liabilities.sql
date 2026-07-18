-- Tracks money the company owes but hasn't paid yet, in two flavors:
--   'team_member'  — someone paid an expense out of pocket and is owed back
--   'installment'  — a purchase being paid off via Fawry installments,
--                    with a flat monthly interest rate applied over N months
-- Each liability accumulates payments in outstanding_payments until fully
-- settled — mirrors the existing invoices/payments pattern rather than
-- inventing a new one.
CREATE TABLE IF NOT EXISTS outstanding_liabilities (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind VARCHAR(20) NOT NULL, -- 'team_member' | 'installment'
  description TEXT,
  team_member_id CHAR(36) NULL, -- for kind='team_member'
  principal_amount DECIMAL(12,2) NOT NULL,
  monthly_interest_rate DECIMAL(5,2) NULL, -- for kind='installment', e.g. 3.21 = 3.21%/month flat
  months INT NULL, -- for kind='installment'
  total_payable DECIMAL(12,2) NOT NULL, -- principal_amount for team_member; principal + flat interest for installment
  start_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'outstanding', -- outstanding | partial | settled
  created_by TEXT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS outstanding_payments (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  liability_id CHAR(36) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  date DATE NOT NULL,
  method VARCHAR(30) NULL,
  recorded_by TEXT,
  INDEX (liability_id)
) ENGINE=InnoDB;
