ALTER TABLE leave_requests
  MODIFY COLUMN type ENUM('vacation','wfh','personal_leave') NOT NULL,
  ADD COLUMN hours DECIMAL(4,2) NULL AFTER days;
