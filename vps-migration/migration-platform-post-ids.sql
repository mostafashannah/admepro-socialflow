-- Multi-platform posts (e.g. Instagram + Facebook) only ever stored ONE
-- external_post_id — whichever platform happened to succeed last in the
-- publish loop overwrote it. That id doesn't necessarily belong to the
-- platform recorded in the legacy `platform` column, so the Insights
-- "Refresh Now" fetch could end up querying (say) Instagram's Graph API
-- with a Facebook video-node id, surfacing as:
--   "Unsupported get request. Object with ID '...' does not exist,
--   cannot be loaded due to missing permissions, or does not support
--   this operation"
-- platform_post_ids stores every platform's own real id, keyed by
-- platform name, so insights lookups can always use the id that actually
-- matches the platform being queried.

ALTER TABLE posts ADD COLUMN platform_post_ids JSON DEFAULT ('{}');
