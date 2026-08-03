ALTER TABLE team_members
  ADD COLUMN extra_hours_banked DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER personal_leave_hours_used;
