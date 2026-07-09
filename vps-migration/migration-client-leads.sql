-- Run once against the live database to support per-client lead capture
-- from the inbox — a "category" (lead / service_provider / hiring) so
-- captured contacts can be told apart, and a UNIQUE index isn't needed
-- since dedup is handled in code via the src_id tag in notes.
ALTER TABLE leads
  ADD COLUMN category VARCHAR(30) DEFAULT 'lead';
