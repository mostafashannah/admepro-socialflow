-- Brand Guidelines sub-tab (Settings > Client Brain > Brand Guidelines) —
-- the specifics Yahia (and Sara) need to design/write on-brand without
-- guessing: exact HEX colors, the logo file, visual direction, and copy
-- language. Read automatically by clientBrainBlock() so every AI agent
-- call includes them, no separate lookup per feature. Run once:
--   mysql -u socialflow_app -p socialflow < migration-client-brand-guidelines.sql

ALTER TABLE client_knowledge ADD COLUMN brand_colors TEXT;
ALTER TABLE client_knowledge ADD COLUMN logo_url TEXT;
ALTER TABLE client_knowledge ADD COLUMN visual_direction TEXT;
ALTER TABLE client_knowledge ADD COLUMN content_language VARCHAR(30);
