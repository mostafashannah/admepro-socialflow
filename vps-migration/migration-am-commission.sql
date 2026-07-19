-- Per-account-manager commission terms for a client — percentage of that
-- client's payments, paid out monthly or quarterly. Stored as JSON keyed by
-- team member id since a client can have more than one account manager:
-- {"<team_member_id>": {"percentage": 10, "cycle": "monthly"}}
ALTER TABLE clients ADD COLUMN account_manager_commissions TEXT NULL;
