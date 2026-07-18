-- Career history log per team member — salary raises, promotions, bonuses,
-- warnings, demotions, etc — shown as a timeline on their profile.
CREATE TABLE IF NOT EXISTS team_member_events (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  team_member_id VARCHAR(36) NOT NULL,
  team_member_name TEXT,
  event_type VARCHAR(30) NOT NULL, -- salary_raise/promotion/bonus/warning/demotion/other
  title TEXT NOT NULL,
  previous_value TEXT,
  new_value TEXT,
  amount DECIMAL(12,2) NULL,
  effective_date TEXT,
  notes TEXT,
  recorded_by TEXT
) ENGINE=InnoDB;
