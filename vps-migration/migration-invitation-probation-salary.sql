-- Adds a probation-period salary (distinct from the final/post-probation
-- salary already stored in `salary`) so an invitation created via "Make
-- Team Member" can carry over the offer's probation terms.
ALTER TABLE user_invitations ADD COLUMN probation_salary DECIMAL(12,2) NULL;
ALTER TABLE user_invitations ADD COLUMN probation_months INT NULL;
ALTER TABLE team_members ADD COLUMN probation_salary DECIMAL(12,2) NULL;
ALTER TABLE team_members ADD COLUMN probation_months INT NULL;
