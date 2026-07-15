-- Job title / seniority (e.g. "Senior Account Manager", "Creative
-- Director") shown on a team member's profile — distinct from `role`,
-- which controls system permissions and must stay one of the fixed
-- functional roles (admin/hr/account_manager/etc).
ALTER TABLE team_members ADD COLUMN title VARCHAR(100) NULL;
