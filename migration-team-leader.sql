-- Team-leader approval step (run once as the MySQL admin):
--   mysql -u root -p socialflow < /var/www/socialflow/migration-team-leader.sql
ALTER TABLE team_members ADD COLUMN team_leader_id VARCHAR(64) NULL;
ALTER TABLE posts
  ADD COLUMN leader_review_status VARCHAR(20) NULL,
  ADD COLUMN leader_review_at DATETIME NULL,
  ADD COLUMN leader_review_to VARCHAR(40) NULL,
  ADD COLUMN leader_reviewer VARCHAR(255) NULL,
  ADD COLUMN leader_paused_secs INT NULL DEFAULT 0,
  ADD COLUMN leader_review_note TEXT NULL;
