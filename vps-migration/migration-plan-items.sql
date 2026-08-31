-- Calendar Plan grouping: a plan's generated posts now start life as
-- "plan items" living inside one parent task card, instead of each
-- immediately becoming its own independent post. An item only becomes a
-- real, independently-movable Post once it's individually split off
-- (see splitPlanItem in app.jsx), so the parent needs somewhere to hold
-- the still-unsplit items' own platform/date/time/media/mini-stage.
ALTER TABLE posts
  ADD COLUMN is_plan_parent TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN plan_items TEXT NULL;
