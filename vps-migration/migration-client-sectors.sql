-- Clients that operate across multiple business lines (e.g. an industrial
-- group with Logistics, Hospitality, Security divisions, each posting
-- distinct content) get an opt-in "Sectors" list, editable from
-- Client Edit Info. When enabled, each post/task can be labeled with one
-- of the client's sectors, and Sara's content generation (Add Calendar
-- Plan, Add Post/Task) can be told to target one specific sector or
-- rotate across all of them.

ALTER TABLE clients ADD COLUMN has_sectors TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE clients ADD COLUMN sectors JSON DEFAULT ('[]');
ALTER TABLE posts ADD COLUMN sector VARCHAR(120) DEFAULT NULL;
