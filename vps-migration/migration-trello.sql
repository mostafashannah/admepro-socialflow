-- Trello two-way sync: each post/task remembers the Trello card it's linked
-- to (if the client's board is connected), so later stage changes move the
-- SAME card instead of creating a new one every time.
ALTER TABLE posts ADD COLUMN trello_card_id VARCHAR(64) NULL;
