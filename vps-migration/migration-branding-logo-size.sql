-- Run once against the live database. The branding_assets logo columns were
-- created as TEXT, which MySQL caps at ~64KB — a base64-encoded logo image
-- blows past that almost immediately, so every upload silently failed (the
-- same issue user_profiles.photo_url already had to be fixed for). Widen
-- them to MEDIUMTEXT (up to ~16MB) to actually fit real images.

ALTER TABLE branding_assets
  MODIFY COLUMN primary_logo MEDIUMTEXT,
  MODIFY COLUMN secondary_logo MEDIUMTEXT,
  MODIFY COLUMN icon_logo MEDIUMTEXT,
  MODIFY COLUMN dark_logo MEDIUMTEXT,
  MODIFY COLUMN light_logo MEDIUMTEXT,
  MODIFY COLUMN watermark_logo MEDIUMTEXT;
