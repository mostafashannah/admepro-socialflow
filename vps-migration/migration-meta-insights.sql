-- Run once against the already-deployed MySQL database to support
-- meta-insights-cron.php (daily Page/IG/Ads metric snapshots) and the
-- "Meta Insights" tab in the Client Detail page.

CREATE TABLE IF NOT EXISTS meta_insights_snapshots (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  integration_id VARCHAR(36) NOT NULL, client_id VARCHAR(36), client_name TEXT,
  platform TEXT, snapshot_date DATE NOT NULL,
  metrics JSON DEFAULT ('{}')
) ENGINE=InnoDB;

-- Also remember to add 'meta_insights_snapshots' to the $ALLOWED_TABLES
-- array in your deployed api.php if it was deployed before this change.
