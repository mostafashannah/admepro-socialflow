-- Contact Reports → "Auto-email on submit" switch (per client). Stored on
-- client_knowledge so both the app and the WhatsApp/Pro path (pro-lib.php)
-- can read it with a plain query, no whitelist changes needed.
-- Run once:
--   mysql -u socialflow_app -p socialflow < migration-contact-report-auto-email.sql

ALTER TABLE client_knowledge ADD COLUMN contact_report_auto_email TINYINT(1) DEFAULT 0;
