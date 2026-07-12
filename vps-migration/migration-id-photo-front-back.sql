-- Run once against the live database. Splits the single "ID Photo" upload at
-- invite time into separate front/back uploads (ID cards have info on both
-- sides). The old id_photo_url column is left in place (unused going
-- forward) rather than dropped, since dropping it isn't necessary for this
-- change and avoids touching any historical data.

ALTER TABLE user_invitations
  ADD COLUMN id_photo_front_url MEDIUMTEXT NULL,
  ADD COLUMN id_photo_back_url MEDIUMTEXT NULL;

ALTER TABLE team_members
  ADD COLUMN id_photo_front_url MEDIUMTEXT NULL,
  ADD COLUMN id_photo_back_url MEDIUMTEXT NULL;
