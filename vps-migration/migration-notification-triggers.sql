-- Supports the new notification-reminders-cron.php, which actually fires
-- the "Due date reminder", "Subscription renewal" and "Daily Digest"
-- toggles under Account > Notifications — previously nothing ever checked
-- them. These flags prevent re-sending the same one-time reminder every
-- time the cron ticks. Run once:
--   sudo mysql socialflow < migration-notification-triggers.sql

ALTER TABLE posts ADD COLUMN due_reminder_sent TINYINT(1) DEFAULT 0;
ALTER TABLE notification_prefs ADD COLUMN digest_last_sent TEXT;
