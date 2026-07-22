-- Adds employee contract generation + policy acceptance to team members:
-- national ID (for the contract), the generated contract file, and a
-- one-time link for the employee to accept the company policy. Run once:
--   mysql -u socialflow_app -p socialflow < migration-employee-contract.sql

ALTER TABLE team_members
  ADD COLUMN national_id VARCHAR(50),
  ADD COLUMN contract_url TEXT,
  ADD COLUMN contract_generated_at TIMESTAMP NULL,
  ADD COLUMN policy_token VARCHAR(64),
  ADD COLUMN policy_accepted_at TIMESTAMP NULL;
