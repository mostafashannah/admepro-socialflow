-- Which month a "Salaries & Payroll" expense actually covers (distinct
-- from `date`, which is just when the payment was recorded/made) — shown
-- on a team member's Payroll tab so it's clear which month's salary each
-- payment was for.
ALTER TABLE expenses ADD COLUMN salary_month VARCHAR(7) NULL; -- 'YYYY-MM'
