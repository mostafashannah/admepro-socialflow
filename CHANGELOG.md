# SocialFlow v30 — Changelog

## What's New vs v29
Complete Asana-style email notification system with per-user granular preferences.

---

## New Features

### 1. Smart Notification Dispatcher
- `sendNotification(eventType, email, subject, html, userPrefs)` — checks user preferences before sending
- Respects master kill switch, mentions-only mode, and per-event toggles
- Falls back to `DEFAULT_NOTIF_PREFS` if no saved preferences found for a user

### 2. Full Email Template Library (15 new templates)

**Task Events**
- `taskAssigned` — assigned team member, project, client, due date, "Open Task" CTA
- `taskStageChanged` — old stage → new stage with colored badge transition
- `taskDueSoon` — yellow warning, due date highlighted
- `taskOverdue` — red alert, overdue since date
- `mentionNotification` — quoted comment text, "Reply in SocialFlow" CTA
- `commentAdded` — comment excerpt for task owner

**Project Events**
- `projectCreated` — project name, client, deadline, manager
- `taskAddedToProject` — task title, project, assignee
- `projectDeadlineUpdated` — old deadline → new deadline comparison

**Client Events**
- `clientApprovalRequired` — sent to client when post moves to client_review stage
- `postApproved` — notifies assignee of approval
- `postRejected` — notifies assignee with client feedback

**Finance Events**
- `invoiceSent` — enhanced with info table (invoice #, amount, due date)
- `paymentReceived` — notifies admin/accountant
- `subscriptionRenewal` — 7-day advance notice to client

**User Events**
- `permissionsUpdated` — role change notification
- Existing: invitation, accessApproved, accessRejected

**Daily Digest**
- `dailyDigest` — summary of due today / overdue / completed / pending tasks per member
- Each section lists task title + client in a clean table

### 3. Automatic Email Triggers

| Event | Who Gets Notified |
|---|---|
| Post/task created with assignee | Assigned team member |
| Post added to project | All project team members (except creator) |
| Task stage changed | Previous assignee + new assignee |
| Task moved to client_review | Client (by email) |
| Task approved/published | Original assignee |
| @mention in comment | Mentioned person |
| New comment on task | Task assignee (if comment_notifications on) |
| Project created | All assigned team members |
| Payment recorded | All admins and accountants |
| Subscription created (renews in ≤7 days) | Client |
| User invited | Invitee |
| Access request approved | Requester |
| Access request rejected | Requester |

### 4. Notification Preferences UI (Account → Notifications tab)

**Master Controls**
- All Notifications on/off (master kill switch)
- Mentions Only Mode — skip everything except @mentions
- Daily Digest on/off

**Task Notifications** (individual toggles)
- Task assigned to me
- Task moved to new stage
- Due date reminder
- Task overdue
- Mentioned in a comment
- New comment on my tasks (off by default)

**Project Notifications**
- Added to a new project
- New task added to project (off by default)
- Project deadline changed

**Client & Approval Notifications**
- Post approved by client
- Post needs revision

**Finance Notifications**
- New invoice created
- Payment received
- Subscription renewal

### 5. Notification Preferences Persistence
- Stored in Supabase `notification_prefs` table per user email
- Loaded in Wave 2 on startup, applied to all outgoing emails
- `saveNotifPrefs()` handler in main App component

---

## SQL Required
Run in Supabase SQL Editor:

```sql
CREATE TABLE IF NOT EXISTS notification_prefs (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  user_email text UNIQUE NOT NULL,
  all_disabled boolean DEFAULT false,
  mentions_only boolean DEFAULT false,
  daily_digest boolean DEFAULT true,
  task_assigned boolean DEFAULT true,
  task_stage_changed boolean DEFAULT true,
  task_due_soon boolean DEFAULT true,
  task_overdue boolean DEFAULT true,
  task_mention boolean DEFAULT true,
  task_comment boolean DEFAULT false,
  project_created boolean DEFAULT true,
  project_task_added boolean DEFAULT false,
  project_deadline_updated boolean DEFAULT true,
  post_approved boolean DEFAULT true,
  post_rejected boolean DEFAULT true,
  client_approval_required boolean DEFAULT true,
  invoice_created boolean DEFAULT true,
  payment_received boolean DEFAULT true,
  subscription_renewal boolean DEFAULT true,
  user_invited boolean DEFAULT true,
  access_approved boolean DEFAULT true,
  access_rejected boolean DEFAULT true,
  permissions_updated boolean DEFAULT true,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);
```
