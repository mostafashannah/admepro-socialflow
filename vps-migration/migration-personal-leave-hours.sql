-- WFH is now a monthly cap (2 days/month, non-shiftable — see
-- monthly-leave-reset-cron.php), not an annual pool: existing rows keep
-- whatever wfh_days_total they had, but new members default to 2.
ALTER TABLE team_members
  MODIFY COLUMN wfh_days_total DECIMAL(6,2) NOT NULL DEFAULT 2;

-- Personal Leave: 4 hours/month, non-shiftable — separate from vacation
-- days so a late-arrival deduction (see attendance-import.php) can come out
-- of this bucket first instead of always hitting vacation_days_used.
ALTER TABLE team_members
  ADD COLUMN personal_leave_hours_total DECIMAL(6,2) NOT NULL DEFAULT 4,
  ADD COLUMN personal_leave_hours_used DECIMAL(6,2) NOT NULL DEFAULT 0;
