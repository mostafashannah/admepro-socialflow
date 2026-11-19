-- Big videos: the scheduler no longer stands and waits for Instagram to process a reel. It starts the processing, saves a note here,
-- and checks again every run (10 minutes) until Instagram says it is done, so a video can take as long as it needs.
-- Run once as root:  mysql -u root -p socialflow < vps-migration/migration-publish-pending.sql
-- Until it is run, reels are waited for inside the run (up to IG_REEL_WAIT_SECONDS, default 9 minutes).
ALTER TABLE posts ADD COLUMN publish_pending TEXT NULL;
