-- Round-robin lead assignment among account managers, with automatic
-- reassignment if a lead sits unactioned past a configurable timeout.
-- assigned_at tracks when the CURRENT assignee got it (reset on every
-- reassignment); rotation_missed flags a lead that was reassigned because
-- its assignee let it time out, used to detect chronic missers.
ALTER TABLE leads
  ADD COLUMN assigned_at TIMESTAMP NULL,
  ADD COLUMN rotation_missed TINYINT(1) DEFAULT 0;

-- Settings blob: {"enabled":false,"timeout_hours":24,"missed_threshold":3,
-- "rotation_order":["team_member_id",...],"rotation_pointer":0}
ALTER TABLE app_settings ADD COLUMN lead_rotation_settings JSON DEFAULT ('{}');
