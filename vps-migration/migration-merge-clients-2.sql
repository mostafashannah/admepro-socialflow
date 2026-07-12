-- Run once, AFTER migration-expenses-split-method.sql. Merges client-payment
-- rows that are really the same client but got recorded under slightly
-- different names/descriptions in the original sheet (e.g. "Ryada" vs
-- "Riyada", or "Partial payment — Silver" vs "Silver").

UPDATE expenses SET description = 'Riyada'
WHERE type = 'in' AND category = 'client_payment' AND description IN ('Ryada', 'Riyada');

UPDATE expenses SET description = 'Silver'
WHERE type = 'in' AND category = 'client_payment'
  AND description IN ('Silver', 'Final payment — Silver', 'Partial payment — Silver');

UPDATE expenses SET description = 'Bino'
WHERE type = 'in' AND category = 'client_payment'
  AND description IN ('Bino', 'Partial payment — Bino');

UPDATE expenses SET description = 'Gold''s Gym'
WHERE type = 'in' AND category = 'client_payment'
  AND description IN ('Gold''s Gym', 'Remaining balance — Gold''s Gym');

UPDATE expenses SET description = 'KAI'
WHERE type = 'in' AND category = 'client_payment' AND description = 'Final payment — KAI';
