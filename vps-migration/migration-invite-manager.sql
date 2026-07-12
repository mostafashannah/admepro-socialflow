-- Run once against the live database. Lets an admin pick the new hire's
-- manager right at invite time (mirrors the Manager field already on
-- team_members / the Edit Member modal), carried through to team_members
-- when the invitee accepts.

ALTER TABLE user_invitations
  ADD COLUMN manager_id CHAR(36) NULL;
