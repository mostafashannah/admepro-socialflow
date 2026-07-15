-- Links a hired job application through to the resulting team member so
-- their CV/portfolio/activity log carry over onto a new "Hiring" tab on
-- their profile once they accept the invitation.
ALTER TABLE job_applications ADD COLUMN linked_team_member_id CHAR(36) NULL;
ALTER TABLE job_applications ADD COLUMN team_member_invited_at DATETIME NULL;
ALTER TABLE user_invitations ADD COLUMN source_application_id VARCHAR(36) NULL;
ALTER TABLE team_members ADD COLUMN source_application_id VARCHAR(36) NULL;
