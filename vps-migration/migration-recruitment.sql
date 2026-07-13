-- Recruitment module: public /careers job-application page + AI CV screening.
-- Run once:
--   sudo mysql socialflow < migration-recruitment.sql

CREATE TABLE IF NOT EXISTS job_openings (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  title TEXT NOT NULL, department TEXT, location TEXT,
  employment_type TEXT DEFAULT ('full_time'),
  description TEXT, requirements TEXT,
  status VARCHAR(20) DEFAULT 'open',
  closing_date TEXT, created_by TEXT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS job_applications (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  job_opening_id VARCHAR(36), job_title TEXT,
  candidate_name TEXT, candidate_email TEXT, candidate_phone TEXT,
  cv_url TEXT, cover_letter TEXT, portfolio_url TEXT, linkedin_url TEXT,
  status VARCHAR(20) DEFAULT 'new',
  ai_score INT, ai_summary TEXT, ai_extracted TEXT,
  ai_review_status VARCHAR(20) DEFAULT 'pending',
  reviewed_by TEXT, notes TEXT
) ENGINE=InnoDB;
