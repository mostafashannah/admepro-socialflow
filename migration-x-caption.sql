-- Run once as the MySQL admin:
--   mysql -u root -p socialflow < /var/www/socialflow/migration-x-caption.sql
-- A separate caption for X (280-character limit) on posts that go to several platforms.
ALTER TABLE posts ADD COLUMN x_caption TEXT NULL;
