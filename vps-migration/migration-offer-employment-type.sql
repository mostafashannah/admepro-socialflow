-- Employment Type (full_time/part_time) and Work Arrangement (on_site/
-- hybrid) fields on the job offer — added to the Offer form/email/
-- response page in app.jsx but the DB columns were missed in that same
-- change, which broke saving an offer entirely: the generic PATCH
-- endpoint runs every field in ONE UPDATE statement, so an unknown
-- column fails the whole save (including status: "offer"), silently
-- reverting the application back to its previous status on refresh.
ALTER TABLE job_applications
  ADD COLUMN offer_employment_type VARCHAR(20) NULL,
  ADD COLUMN offer_work_arrangement VARCHAR(20) NULL;
