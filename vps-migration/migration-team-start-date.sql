-- Real hire/start date for a team member — used to skip them from a
-- payroll month they hadn't joined yet, and to prorate their first
-- partial month. Previously the payroll cron had no such field at all
-- and treated every active member as if they'd worked the full month
-- since day one.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'team_members' AND COLUMN_NAME = 'start_date'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE team_members ADD COLUMN start_date DATE NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
