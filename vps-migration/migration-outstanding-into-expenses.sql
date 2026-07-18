-- Folds the just-added Outstanding feature directly into the expense/
-- transaction flow instead of a separate liabilities table — an expense's
-- payment method can now be "Outstanding", revealing whether it's owed to
-- a team member or a Fawry installment plan. The expense row itself IS
-- the outstanding record; outstanding_payments now tracks payments against
-- an expense id directly. Safe to drop outstanding_liabilities — it shipped
-- moments ago with no real data yet.
DROP TABLE IF EXISTS outstanding_liabilities;

ALTER TABLE expenses ADD COLUMN outstanding_kind VARCHAR(20) NULL; -- 'team_member' | 'installment' | NULL (not outstanding)
ALTER TABLE expenses ADD COLUMN outstanding_status VARCHAR(20) NULL; -- 'outstanding' | 'partial' | 'settled'
ALTER TABLE expenses ADD COLUMN outstanding_monthly_interest_rate DECIMAL(5,2) NULL; -- for kind='installment'
ALTER TABLE expenses ADD COLUMN outstanding_months INT NULL; -- for kind='installment'
ALTER TABLE expenses ADD COLUMN outstanding_total_payable DECIMAL(12,2) NULL; -- amount for team_member; principal+flat interest for installment

ALTER TABLE outstanding_payments CHANGE COLUMN liability_id expense_id CHAR(36) NOT NULL;
