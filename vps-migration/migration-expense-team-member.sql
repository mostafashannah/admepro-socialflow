-- Links a "Salaries & Payroll" expense to the specific team member it was
-- paid to, so their profile's Payroll tab can show their own salary
-- history instead of just the aggregate agency-wide expense list.
ALTER TABLE expenses ADD COLUMN team_member_id CHAR(36) NULL;
CREATE INDEX idx_expenses_team_member ON expenses(team_member_id);
