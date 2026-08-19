-- Remembers whatever stage a post was ACTUALLY in right before it reached
-- Client Approval — used by the Trello "comments only + sync approval
-- moves" integration mode to send a rejected/bounced-back card back to the
-- correct real prior stage, since several stages usually share one Trello
-- list and a plain list->stage lookup can't tell them apart.
ALTER TABLE posts
  ADD COLUMN pre_approval_stage VARCHAR(30) NULL;
