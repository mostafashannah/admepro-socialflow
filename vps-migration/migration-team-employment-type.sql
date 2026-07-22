-- Adds Full-time/Part-time to team members, plus which weekdays a
-- part-time member actually works — used by calendar-plan task
-- allocation (findFirstAvailableSlot/scheduleItemDates) so a part-timer's
-- off-days aren't treated as free capacity. Run once:
--   mysql -u socialflow_app -p socialflow < migration-team-employment-type.sql

ALTER TABLE team_members
  ADD COLUMN employment_type VARCHAR(20) DEFAULT 'full_time',
  ADD COLUMN work_days TEXT;
