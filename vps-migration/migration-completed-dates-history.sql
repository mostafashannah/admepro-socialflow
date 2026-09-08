-- A task that gets sent back for revision (rejected/returned) and worked on
-- again reuses the SAME design_completed_at/content_completed_at column,
-- overwriting whatever day it was completed on last time — so the Timeline
-- could only ever show it as "done" on the most recent completion day, not
-- every day someone actually did real work on it (e.g. finished yesterday,
-- sent back, finished again today). These columns accumulate every distinct
-- completion date as a JSON array (e.g. ["2026-08-19","2026-08-20"]) so the
-- Timeline can show the task on every day it was genuinely worked on.
ALTER TABLE posts
  ADD COLUMN design_completed_dates TEXT NULL,
  ADD COLUMN content_completed_dates TEXT NULL;
