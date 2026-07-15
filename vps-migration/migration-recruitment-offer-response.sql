-- Candidate response to a sent offer (accept / negotiate with a letter /
-- reject), via a public link — mirrors the interview-scheduling flow.
ALTER TABLE job_applications
  ADD COLUMN offer_token VARCHAR(64) NULL,
  ADD COLUMN offer_candidate_response VARCHAR(20) NULL,
  ADD COLUMN offer_negotiation_letter TEXT NULL,
  ADD COLUMN offer_responded_at DATETIME NULL;
CREATE INDEX idx_job_applications_offer_token ON job_applications(offer_token);
