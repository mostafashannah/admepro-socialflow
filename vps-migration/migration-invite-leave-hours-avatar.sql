-- InviteUserModal's form has included personal_leave_hours_total (default
-- 4) and avatar_url for a while now, but user_invitations never had
-- matching columns — every invitation ever submitted through this modal
-- has been silently failing to save (ce("UserInvitation") returns a save
-- error), which is why an invitation appears in the UI then vanishes on
-- refresh: it only ever existed in local optimistic state. Same root
-- cause as the earlier "title" column miss (see migration-invite-title.sql).
ALTER TABLE user_invitations
  ADD COLUMN personal_leave_hours_total DECIMAL(6,2) NULL,
  ADD COLUMN avatar_url MEDIUMTEXT NULL;
