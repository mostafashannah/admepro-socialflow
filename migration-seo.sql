-- SEO Growth agent (Website → SEO agent). Safe to run more than once.
CREATE TABLE IF NOT EXISTS seo_suggestions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NULL,
  type VARCHAR(16) NOT NULL DEFAULT 'meta',           -- meta | content | technical | conversion | offsite
  priority INT NOT NULL DEFAULT 50,
  title VARCHAR(255) NOT NULL,
  why TEXT NULL,
  evidence TEXT NULL,                                 -- JSON: query, page, impressions, clicks, position, markets
  changes MEDIUMTEXT NULL,                            -- JSON list of {path, op, field, before, after | json}
  manual_steps TEXT NULL,                             -- JSON list (offsite / things only a person can do)
  status VARCHAR(12) NOT NULL DEFAULT 'pending',      -- pending | applied | rejected | failed | reverted | done
  auto_applied TINYINT NOT NULL DEFAULT 0,
  decided_by VARCHAR(255) NULL,
  decided_at DATETIME NULL,
  commit_sha VARCHAR(12) NULL,
  error VARCHAR(500) NULL,
  measure_after DATE NULL,
  result TEXT NULL,                                   -- JSON: before/after clicks, impressions, position
  created_at DATETIME NOT NULL,
  KEY idx_status (status, priority),
  KEY idx_measure (status, measure_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS seo_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'running',      -- running | done | error
  trigger_by VARCHAR(255) NULL,
  added INT NOT NULL DEFAULT 0,
  summary TEXT NULL,
  error VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS seo_settings (
  k VARCHAR(40) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
