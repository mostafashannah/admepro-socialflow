-- Ensures the system_sessions table exists on the live database.
-- Login Sessions / Unique IPs / Countries showing 0 on the System Log page
-- (while Total Actions works fine) is the exact symptom of this table never
-- having been created — inserts on login and reads on the System Log page
-- both fail silently (both wrapped in .catch), so nothing ever appears and
-- no error surfaces either. Safe to re-run: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS system_sessions (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_email TEXT, user_name TEXT, user_role VARCHAR(50),
  ip_address VARCHAR(64), country TEXT, country_code VARCHAR(10),
  region TEXT, city TEXT, isp TEXT, org TEXT,
  latitude DOUBLE, longitude DOUBLE,
  browser TEXT, os TEXT, device_type VARCHAR(20),
  screen_resolution VARCHAR(20), viewport VARCHAR(20),
  timezone VARCHAR(64), language VARCHAR(20),
  user_agent TEXT, login_at TIMESTAMP NULL, page_url TEXT
) ENGINE=InnoDB;
