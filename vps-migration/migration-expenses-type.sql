-- Run once against the live database, after migration-expenses.sql, to let the
-- expenses table hold income entries too (the Finance page's "Add Transaction"
-- modal now supports both Expense and Income types on the same table).

ALTER TABLE expenses ADD COLUMN type VARCHAR(10) NOT NULL DEFAULT 'out'; -- 'out' | 'in'
