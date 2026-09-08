-- A post published to more than one platform (Instagram + Facebook, say)
-- only ever had ONE set of insight_likes/comments/shares/reach columns,
-- fetched using the legacy single `platform` column — so the "All
-- Platforms" Insights summary attributed the post's engagement entirely to
-- whichever platform happened to be in that column, and any other platform
-- the post actually went out to (Facebook, usually, since `platform`
-- stayed on whatever was picked first) never got its own numbers fetched
-- or shown at all.
--
-- insights_by_platform stores each platform's own likes/comments/shares/
-- reach separately, keyed by platform name. The legacy insight_* columns
-- are kept in sync as the SUM across every platform, for anything that
-- still reads those directly.

ALTER TABLE posts ADD COLUMN insights_by_platform JSON DEFAULT ('{}');
