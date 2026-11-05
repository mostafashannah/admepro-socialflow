ALTER TABLE payroll_runs
  ADD COLUMN termination_deduction DECIMAL(10,2) NULL DEFAULT 0,
  ADD COLUMN termination_deduction_status VARCHAR(20) NULL;
