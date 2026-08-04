UPDATE team_members
SET wfh_days_used = 2
WHERE id COLLATE utf8mb4_unicode_ci = (
  SELECT team_member_id COLLATE utf8mb4_unicode_ci FROM leave_requests WHERE id = 'cbaedcfa-8ffc-4b05-aa76-17adb27eae56'
);
