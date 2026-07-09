-- Tracks which conversation threads backfill-leads.php has already checked
-- (regardless of whether they turned out to be an actual lead) so re-running
-- the script doesn't re-classify (and re-bill Claude API calls for) threads
-- that were correctly classified as "other" and skipped.
CREATE TABLE IF NOT EXISTS lead_backfill_seen (
  id CHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  client_id VARCHAR(36) NOT NULL,
  channel VARCHAR(20) NOT NULL,
  customer_id VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_seen (client_id, channel, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
