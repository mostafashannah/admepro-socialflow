-- auto-publish.php only ever read posts.platform (the single legacy column),
-- never posts.platforms (the JSON array the in-app multi-platform Publish
-- button actually uses) — so a post scheduled for Instagram + Facebook
-- silently published to only one of them. This column tracks which
-- platforms a given post has already been successfully published to, so
-- the fixed cron can publish to the rest without re-posting the ones
-- already live on a retry.
ALTER TABLE posts ADD COLUMN published_platforms JSON DEFAULT ('[]');
