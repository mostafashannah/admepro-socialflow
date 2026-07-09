-- Per-client, per-category WhatsApp notification settings for the Leads tab.
-- When a new contact is captured in a category, if a number is configured
-- here for that client+category, we send it the full lead details via
-- WhatsApp (separate from the existing admin-role notifyAdminsOfNewLead()).
CREATE TABLE IF NOT EXISTS lead_notify_settings (
  id VARCHAR(36) NOT NULL PRIMARY KEY,
  client_id VARCHAR(36) NOT NULL,
  category VARCHAR(30) NOT NULL,
  whatsapp_number VARCHAR(30) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_client_category (client_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
