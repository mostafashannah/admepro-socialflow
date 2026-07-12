-- Run once against the live database. Adds an optional check number, a
-- system-generated reference code, and file attachments (invoice/quotation/
-- transfer screenshot etc) to each Finance transaction.

ALTER TABLE expenses ADD COLUMN check_no VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE expenses ADD COLUMN ref VARCHAR(50) NULL DEFAULT NULL;
ALTER TABLE expenses ADD COLUMN attachments TEXT NULL DEFAULT NULL; -- JSON array of {url,name}
