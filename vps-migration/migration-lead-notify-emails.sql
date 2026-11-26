-- Per-client, per-category EMAIL recipients for new-lead notifications (comma separated), next to the WhatsApp numbers.
ALTER TABLE lead_notify_settings ADD COLUMN emails TEXT NULL;
