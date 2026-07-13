-- Adds support for configurable attendance rules (Users > Attendance tab,
-- admin only): automatic vacation-credit deductions for late arrivals and
-- unapproved absences, applied by attendance-import.php on every CSV upload.
-- Run once against the live database:
--   sudo mysql socialflow < migration-attendance-rules.sql

ALTER TABLE attendance_records
  ADD COLUMN late_deducted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN absence_deducted TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE app_settings
  ADD COLUMN attendance_rules JSON DEFAULT ('{}');
