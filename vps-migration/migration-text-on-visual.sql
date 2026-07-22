-- Adds a "Text on Visual" field to posts — the short overlay text/headline
-- meant to sit on the actual design/graphic, distinct from the social
-- caption. Run once:
--   mysql -u root -p socialflow < migration-text-on-visual.sql

ALTER TABLE posts
  ADD COLUMN text_on_visual TEXT;
