-- Keeps a history of due-date moves on tasks, so a task that was late and then moved to a later day still shows (as delayed)
-- on the day it was originally due. Filled automatically by a trigger, whoever or whatever changes the due date.
ALTER TABLE posts ADD COLUMN due_history TEXT NULL;
DROP TRIGGER IF EXISTS posts_due_history_bu;
CREATE TRIGGER posts_due_history_bu BEFORE UPDATE ON posts FOR EACH ROW
  SET NEW.due_history = IF(
    OLD.due_date IS NOT NULL AND NEW.due_date IS NOT NULL AND NOT (LEFT(OLD.due_date,10) <=> LEFT(NEW.due_date,10))
      AND OLD.stage NOT IN ('published','scheduled','approved','rejected','client_request','on_hold'),
    JSON_ARRAY_APPEND(IF(OLD.due_history IS NULL OR OLD.due_history = '', JSON_ARRAY(), CAST(OLD.due_history AS JSON)), '$',
      JSON_OBJECT('date', LEFT(OLD.due_date,10), 'time', OLD.due_time, 'minutes', OLD.estimated_minutes, 'to', LEFT(NEW.due_date,10),
                  'at', DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'))),
    NEW.due_history);
