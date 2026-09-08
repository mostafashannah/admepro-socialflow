-- Add Title column to client_users — the Add/Edit Client User modals now
-- collect a job title (e.g. "Marketing Manager") alongside the existing
-- mobile/phone field, since contact reports display it for client
-- attendees. Written without "ADD COLUMN IF NOT EXISTS" since that
-- requires MySQL 8.0.29+ and this server runs an older version.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_users' AND COLUMN_NAME = 'title'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE client_users ADD COLUMN title TEXT', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
