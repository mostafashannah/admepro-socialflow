-- Marks a leave_requests row as an agency-wide declared day off/WFH day
-- (e.g. a national holiday) rather than a personal request — these are
-- auto-created already-approved for every active team member and must
-- never deduct from anyone's individual vacation/WFH credit.
ALTER TABLE leave_requests ADD COLUMN is_company_wide TINYINT(1) NOT NULL DEFAULT 0;
