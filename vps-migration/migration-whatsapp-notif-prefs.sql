-- New per-user toggles for WhatsApp-only notification categories that were
-- previously sent unconditionally to every admin/manager with a
-- whatsapp_number on file, with no way to opt out. All default to 1 (on)
-- so nobody's current behavior changes until they actively flip one off.
ALTER TABLE notification_prefs
  ADD COLUMN wa_recruitment_candidate_response TINYINT(1) DEFAULT 1,
  ADD COLUMN wa_daily_finance_report TINYINT(1) DEFAULT 1,
  ADD COLUMN wa_morning_greeting TINYINT(1) DEFAULT 1,
  ADD COLUMN wa_leave_requests TINYINT(1) DEFAULT 1,
  ADD COLUMN wa_mention_messages TINYINT(1) DEFAULT 1;
