-- Termination workflow: a last working day (used to prorate their final
-- month's payroll and to know when to auto-deactivate them — the day
-- AFTER this date, not immediately, since they're still meant to be
-- working/have access up through it) and which letter template was used.
ALTER TABLE team_members
  ADD COLUMN termination_date DATE NULL,
  ADD COLUMN termination_reason VARCHAR(50) NULL;
