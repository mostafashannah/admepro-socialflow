-- Routine tasks: no client/project, a checklist, closed by the member with an end note, expire if missed.
ALTER TABLE posts
  ADD COLUMN is_routine TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN checklist TEXT NULL,
  ADD COLUMN routine_status VARCHAR(12) NULL,
  ADD COLUMN routine_note TEXT NULL,
  ADD COLUMN routine_flag VARCHAR(8) NULL,
  ADD COLUMN routine_completed_at DATETIME NULL;
ALTER TABLE recurring_tasks ADD COLUMN checklist TEXT NULL;
