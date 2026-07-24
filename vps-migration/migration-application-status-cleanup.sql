-- Recruitment → Cleanup Rules: auto-delete CV/portfolio attachments for
-- applications that have sat in a given stage (e.g. Rejected) for N days,
-- to keep storage from filling up with old candidates' files indefinitely.
-- Needs to know WHEN an application entered its current status, which
-- wasn't tracked before — status_updated_at is set on every future status
-- change (app.jsx's handleUpdateStatus, pro-lib.php's update_application_
-- status/confirm-interview paths) and backfilled to created_at here so
-- existing applications are immediately eligible based on age, not stuck
-- looking "brand new" forever.
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-application-status-cleanup.sql

ALTER TABLE job_applications ADD COLUMN status_updated_at DATETIME NULL;
UPDATE job_applications SET status_updated_at = created_at WHERE status_updated_at IS NULL;

-- Also tracks which applications already had their attachments cleaned up,
-- so re-running the cleanup cron doesn't need to re-check disk every time
-- and so the app can show "Attachments removed" instead of a dead link.
ALTER TABLE job_applications ADD COLUMN attachments_cleaned_at DATETIME NULL;
