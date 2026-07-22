-- Adds recruitment alert toggles to notification_prefs, so admins/HR can
-- control (per-person) whether they get pinged about new applications,
-- task submissions, and reschedule requests — same on/off pattern as the
-- existing task/project/finance notification toggles. Run once:
--   mysql -u socialflow_app -p socialflow < migration-recruitment-notif-prefs.sql

ALTER TABLE notification_prefs
  ADD COLUMN recruitment_new_application TINYINT(1) DEFAULT 1,
  ADD COLUMN recruitment_task_submitted TINYINT(1) DEFAULT 1,
  ADD COLUMN recruitment_reschedule_request TINYINT(1) DEFAULT 1;
