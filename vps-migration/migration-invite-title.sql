-- InviteUserModal added a "title" field to the invite form, but
-- user_invitations never had a matching column — every invite created
-- after that shipped was silently failing to save (ce("UserInvitation")
-- returned a save error), which is why the invitation would appear then
-- vanish on refresh: it only ever existed in local optimistic state.
ALTER TABLE user_invitations ADD COLUMN title VARCHAR(100) NULL;
