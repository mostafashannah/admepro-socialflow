-- Business-profile fields an account manager fills in after the first real
-- call with a lead — business name, which services they want, social
-- accounts, and how established the business is. Lets Mai reference real
-- specifics in her AM check-ins instead of just "did you call them".
ALTER TABLE leads
  ADD COLUMN business_name TEXT NULL,
  ADD COLUMN social_accounts JSON DEFAULT ('[]'),
  ADD COLUMN interested_services JSON DEFAULT ('[]'),
  ADD COLUMN business_stage VARCHAR(20) NULL;
