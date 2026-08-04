ALTER TABLE leave_requests
  ADD COLUMN start_time TIME NULL AFTER hours,
  ADD COLUMN end_time TIME NULL AFTER start_time;
