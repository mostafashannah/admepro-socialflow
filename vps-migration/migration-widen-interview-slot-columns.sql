-- interview_selected_slot / interview_confirmed_slot were VARCHAR(60) —
-- fine for an exact-time slot label ("Sep 17, 2026, 1:00 PM"), but a
-- "window" slot built by slotRange() in app.jsx (e.g. "Thursday, Sep 17,
-- 1:00 PM–6:00 PM (any time in this window works)") runs 65+ characters,
-- so MySQL rejected the UPDATE outright. Because ue() swallows the error
-- and the caller never checked its return value, the app still reported
-- "confirmed" and WhatsApp-notified staff even though NOTHING was saved —
-- exactly why Pro couldn't find a candidate whose interview staff had
-- already been told (via WhatsApp) was confirmed.
ALTER TABLE job_applications
  MODIFY COLUMN interview_selected_slot VARCHAR(160) NULL,
  MODIFY COLUMN interview_confirmed_slot VARCHAR(160) NULL;
