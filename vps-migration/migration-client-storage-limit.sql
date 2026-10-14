-- Per-client cap (in MB) on the shared "Client Assets" folder; NULL = unlimited.
ALTER TABLE clients ADD COLUMN storage_limit_mb INT NULL;
