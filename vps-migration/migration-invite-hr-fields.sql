-- Run once against the live database to let admins set a new team member's
-- WhatsApp number, salary, leave credits, and ID photo right at invite time
-- (instead of only after they accept), and to fix a pre-existing bug where
-- AcceptInvitationPage tried to insert into a non-existent team_members.mobile
-- column (team_members only ever had whatsapp_number).

ALTER TABLE user_invitations
  ADD COLUMN whatsapp_number TEXT,
  ADD COLUMN salary DECIMAL(12,2) NULL,
  ADD COLUMN vacation_days_total DECIMAL(6,2) NULL,
  ADD COLUMN wfh_days_total DECIMAL(6,2) NULL,
  ADD COLUMN id_photo_url MEDIUMTEXT NULL;

ALTER TABLE team_members
  ADD COLUMN id_photo_url MEDIUMTEXT NULL;
