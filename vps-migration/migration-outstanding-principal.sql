-- Stores the original principal separately from `amount` (which holds the
-- full total-payable-including-interest for installment plans) so editing
-- an existing outstanding record doesn't re-apply interest on top of an
-- already-interest-inflated amount, and so the Outstanding tab can show
-- the true principal instead of the total.
ALTER TABLE expenses ADD COLUMN outstanding_principal_amount DECIMAL(12,2) NULL;
