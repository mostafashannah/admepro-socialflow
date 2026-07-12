-- Run once, AFTER migration-expenses-split-method.sql (so descriptions are
-- already stripped of the payment method suffix). Merges "Al Mousa" and
-- "Al Mousa Group" into a single client name so they group together in the
-- Finance Clients tab instead of showing as two separate clients.

UPDATE expenses
SET description = 'Al Mousa Group'
WHERE type = 'in' AND category = 'client_payment' AND description = 'Al Mousa';
