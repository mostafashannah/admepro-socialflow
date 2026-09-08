-- Decouples "exempt from attendance tracking / vacation policy" from the
-- Employment Type dropdown — a part-time member can now ALSO be exempt
-- (no fingerprint tracking, no vacation-day deductions) while still being
-- scheduled on their normal part-time work days for task assignment,
-- instead of only full freelancers (no scheduling at all) getting the
-- exemption.
ALTER TABLE team_members
  ADD COLUMN attendance_policy_exempt TINYINT(1) NOT NULL DEFAULT 0;
