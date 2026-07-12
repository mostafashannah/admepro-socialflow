-- Run once against the live database. The 2026 sheet import baked the payment
-- method into the description for income rows, e.g. "Al Mousa (Bank transfer)".
-- That breaks client grouping/matching since "Al Mousa" and "Al Mousa (Cash)"
-- read as two different clients. This splits it into a separate `method`
-- column and leaves description as just the client/payer name.

ALTER TABLE expenses ADD COLUMN method VARCHAR(30) NULL DEFAULT NULL;

UPDATE expenses
SET
  method = TRIM(TRAILING ')' FROM SUBSTRING_INDEX(description, '(', -1)),
  description = TRIM(TRAILING ' ' FROM SUBSTRING_INDEX(description, '(', 1))
WHERE type = 'in'
  AND category = 'client_payment'
  AND description LIKE '%(%)%';
