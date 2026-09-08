-- Per-client toggles: Auto Scheduling (new posts get a date/time picked
-- automatically vs always typed in by hand) and Auto Publishing (whether
-- auto-publish.php's cron is allowed to actually publish this client's
-- posts once their scheduled_date/time arrives). Independent of each
-- other on purpose.
SET @col1 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_intelligence' AND COLUMN_NAME = 'auto_schedule_enabled');
SET @sql1 := IF(@col1 = 0, 'ALTER TABLE client_intelligence ADD COLUMN auto_schedule_enabled TINYINT(1) DEFAULT 1', 'SELECT 1');
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @col2 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_intelligence' AND COLUMN_NAME = 'auto_publish_enabled');
SET @sql2 := IF(@col2 = 0, 'ALTER TABLE client_intelligence ADD COLUMN auto_publish_enabled TINYINT(1) DEFAULT 1', 'SELECT 1');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
