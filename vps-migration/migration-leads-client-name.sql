-- Fixes a pre-existing bug: maybeCreateLeadFromMessage() (admepro's own inbox
-- lead capture) and maybeCaptureClientContact() (managed clients' lead
-- capture) both insert a client_name value, but the leads table never had
-- that column — every capture attempt has been silently failing with
-- "Unknown column 'client_name'" since before this feature was even visible
-- in the app. This explains why NO leads were ever actually captured, live
-- or via backfill, despite clear phone numbers/interest in the source
-- messages.
ALTER TABLE leads
  ADD COLUMN client_name TEXT NULL;
