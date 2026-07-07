-- Run once against the live database to let comments carry an attached
-- file (image, video, or any other document), like Asana's task comments.
ALTER TABLE comments
  ADD COLUMN file_url MEDIUMTEXT NULL,
  ADD COLUMN file_name TEXT NULL,
  ADD COLUMN file_type VARCHAR(20) NULL;
