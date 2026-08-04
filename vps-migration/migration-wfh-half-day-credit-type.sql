ALTER TABLE leave_credit_events
  MODIFY COLUMN credit_type ENUM('personal_leave_hours','wfh_days','vacation_days','wfh_half_day') NOT NULL;
