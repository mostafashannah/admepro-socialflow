-- ================================================================
-- SocialFlow — MySQL Schema (replaces Supabase/Postgres)
-- Run this ONCE on your VPS MySQL/MariaDB server:
--   mysql -u root -p socialflow < mysql-schema.sql
--
-- Conventions:
--   id            CHAR(36) PRIMARY KEY DEFAULT (UUID())
--   created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
--   jsonb (pg)    -> JSON
--   boolean (pg)  -> TINYINT(1)
--   numeric (pg)  -> DECIMAL(14,2) unless clearly an integer count
--   text (pg)     -> TEXT (VARCHAR(255) only where UNIQUE/indexed is needed)
-- Requires MySQL 8.0.13+ / MariaDB 10.2+ for expression defaults (UUID()).
-- ================================================================

CREATE DATABASE IF NOT EXISTS socialflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE socialflow;

SET sql_mode = (SELECT REPLACE(@@sql_mode,'STRICT_TRANS_TABLES',''));

-- ----------------------------------------------------------------
-- POSTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS posts (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  project_id VARCHAR(36), client_id VARCHAR(36), client_name TEXT,
  title TEXT NOT NULL, description TEXT, stage VARCHAR(50) DEFAULT 'planning',
  platform TEXT, post_type TEXT, caption TEXT, hashtags TEXT,
  design_urls JSON DEFAULT ('[]'), scheduled_date TEXT, scheduled_time TEXT,
  assigned_to TEXT, priority TEXT DEFAULT ('medium'), rejection_reason TEXT,
  reel_hook TEXT, reel_script TEXT, reel_cta TEXT,
  carousel_cover TEXT, carousel_slides JSON DEFAULT ('[]'),
  music_direction TEXT, tov_used TEXT, content_language TEXT,
  design_assets JSON DEFAULT ('[]'), brief TEXT, notes TEXT,
  published_at TIMESTAMP NULL, external_post_id TEXT, publish_error TEXT,
  publish_attempts DECIMAL(4,0) DEFAULT 0
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- PROJECTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  title TEXT NOT NULL, description TEXT,
  client_id VARCHAR(36), client_name TEXT NOT NULL,
  status VARCHAR(50) DEFAULT 'active',
  start_date TEXT, end_date TEXT,
  posting_start DATE, posting_end DATE,
  platforms JSON DEFAULT ('[]'), team_members JSON DEFAULT ('[]')
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CLIENTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT, logo_url TEXT,
  industry TEXT, status VARCHAR(50) DEFAULT 'active', account_manager_id TEXT,
  notes TEXT, platforms JSON DEFAULT ('[]'), portal_password TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- TEAM MEMBERS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS team_members (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL, email VARCHAR(255) NOT NULL, role VARCHAR(50) DEFAULT 'content_creator',
  status VARCHAR(50) DEFAULT 'active', avatar_url MEDIUMTEXT, department TEXT,
  whatsapp_number TEXT,
  permissions TEXT, password TEXT,
  UNIQUE KEY uq_team_members_email (email)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- COMMENTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comments (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  post_id VARCHAR(36) NOT NULL, content TEXT NOT NULL,
  author_name TEXT, author_email TEXT, type VARCHAR(50) DEFAULT 'comment',
  mentions JSON DEFAULT ('[]')
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- ASSETS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assets (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL, file_url TEXT NOT NULL, file_type TEXT,
  category TEXT, tags JSON DEFAULT ('[]'), project_id VARCHAR(36),
  description TEXT, file_size DECIMAL(14,2), thumbnail_url TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- TIME LOGS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS time_logs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  project_id VARCHAR(36), task_name TEXT, duration_minutes DECIMAL(14,2),
  notes TEXT, logged_by TEXT, log_date TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- NOTIFICATIONS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recipient_email TEXT NOT NULL, title TEXT NOT NULL, message TEXT,
  type VARCHAR(50) DEFAULT 'info', is_read TINYINT(1) DEFAULT 0,
  link_id TEXT, link_type TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- NOTIFICATION PREFS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_prefs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  user_email VARCHAR(255) UNIQUE NOT NULL,
  all_disabled TINYINT(1) DEFAULT 0,
  mentions_only TINYINT(1) DEFAULT 0,
  daily_digest TINYINT(1) DEFAULT 1,
  task_assigned TINYINT(1) DEFAULT 1,
  task_stage_changed TINYINT(1) DEFAULT 1,
  task_due_soon TINYINT(1) DEFAULT 1,
  task_overdue TINYINT(1) DEFAULT 1,
  task_mention TINYINT(1) DEFAULT 1,
  task_comment TINYINT(1) DEFAULT 0,
  project_created TINYINT(1) DEFAULT 1,
  project_task_added TINYINT(1) DEFAULT 0,
  project_deadline_updated TINYINT(1) DEFAULT 1,
  post_approved TINYINT(1) DEFAULT 1,
  post_rejected TINYINT(1) DEFAULT 1,
  client_approval_required TINYINT(1) DEFAULT 1,
  invoice_created TINYINT(1) DEFAULT 1,
  payment_received TINYINT(1) DEFAULT 1,
  subscription_renewal TINYINT(1) DEFAULT 1,
  user_invited TINYINT(1) DEFAULT 1,
  access_approved TINYINT(1) DEFAULT 1,
  access_rejected TINYINT(1) DEFAULT 1,
  permissions_updated TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- TEMPLATES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS templates (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL, platform TEXT, post_type TEXT,
  caption_template TEXT, hashtags TEXT, category TEXT,
  description TEXT, thumbnail_url TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- QUOTES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quotes (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  quote_number TEXT, title TEXT, status VARCHAR(50) DEFAULT 'draft',
  client_id VARCHAR(36), client_name TEXT, client_phone TEXT, client_email TEXT,
  date TEXT, due_date TEXT, items TEXT,
  subtotal DECIMAL(14,2), discount_type TEXT DEFAULT ('percent'),
  discount_value DECIMAL(14,2) DEFAULT 0, tax_rate DECIMAL(6,2) DEFAULT 0,
  total DECIMAL(14,2), currency VARCHAR(10) DEFAULT 'USD',
  payment_terms TEXT, notes TEXT, created_by TEXT, pdf_url TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- LEADS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL, company TEXT, phone TEXT, email TEXT,
  source TEXT DEFAULT ('website'), status VARCHAR(50) DEFAULT 'new',
  assigned_to TEXT, followup_date TEXT, value DECIMAL(14,2),
  currency VARCHAR(10) DEFAULT 'USD', platforms JSON DEFAULT ('[]'),
  notes TEXT, converted TINYINT(1) DEFAULT 0,
  client_id VARCHAR(36), tags JSON DEFAULT ('[]')
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- LEAD ACTIVITIES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_activities (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lead_id VARCHAR(36) NOT NULL, content TEXT NOT NULL,
  author_name TEXT, author_email TEXT,
  type TEXT DEFAULT ('note'), followup_date TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- INVOICES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  invoice_number TEXT, quote_id VARCHAR(36),
  client_id VARCHAR(36), client_name TEXT, client_email TEXT, client_phone TEXT,
  title TEXT, issue_date TEXT, due_date TEXT, currency VARCHAR(10) DEFAULT 'USD',
  items TEXT, subtotal DECIMAL(14,2),
  discount_value DECIMAL(14,2) DEFAULT 0, discount_type TEXT DEFAULT ('percent'),
  tax_rate DECIMAL(6,2) DEFAULT 0, total DECIMAL(14,2),
  amount_paid DECIMAL(14,2) DEFAULT 0, balance_due DECIMAL(14,2),
  status VARCHAR(50) DEFAULT 'unpaid', payment_terms TEXT,
  notes TEXT, created_by TEXT, sent_at TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- PAYMENTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  invoice_id VARCHAR(36) NOT NULL, invoice_number TEXT, amount DECIMAL(14,2),
  method TEXT DEFAULT ('bank'), payment_date TEXT, reference TEXT,
  notes TEXT, confirmed_by TEXT, recorded_by TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- INTEGRATIONS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS integrations (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL, app_key TEXT NOT NULL, app_category TEXT,
  `trigger` TEXT, action TEXT, status VARCHAR(50) DEFAULT 'inactive',
  credentials TEXT, config TEXT, webhook_url TEXT,
  last_run_at TEXT, last_run_status TEXT DEFAULT ('never'),
  last_run_message TEXT, run_count DECIMAL(14,0) DEFAULT 0, error_count DECIMAL(14,0) DEFAULT 0,
  created_by TEXT, icon_url TEXT, template_id TEXT,
  client_id VARCHAR(36), client_name TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- INTEGRATION LOGS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS integration_logs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  integration_id VARCHAR(36) NOT NULL, integration_name TEXT,
  status TEXT, duration_ms DECIMAL(14,2), error TEXT,
  payload_summary TEXT, triggered_by TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CUSTOMER MESSAGES (per-client social inbox: Messenger/Instagram/WhatsApp)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_messages (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_id VARCHAR(36), client_name TEXT,
  channel VARCHAR(20) NOT NULL, -- messenger | instagram | whatsapp
  customer_id TEXT, customer_name TEXT,
  direction VARCHAR(10) NOT NULL, -- in | out
  message_text TEXT, sent_by VARCHAR(20) DEFAULT 'customer', -- customer | bot | human
  thread_status VARCHAR(20) DEFAULT 'open', -- open | bot_handled | needs_human | closed
  draft_status VARCHAR(20) DEFAULT NULL -- pending_review | sent | dismissed (NULL = not a bot draft)
) ENGINE=InnoDB;
CREATE INDEX idx_customer_messages_client ON customer_messages(client_id);
CREATE INDEX idx_customer_messages_customer ON customer_messages(customer_id);

-- ----------------------------------------------------------------
-- REPLY BOT SETTINGS (per-client AI auto-reply configuration)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reply_bot_settings (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  client_id VARCHAR(36) UNIQUE NOT NULL, client_name TEXT,
  enabled TINYINT(1) DEFAULT 0,
  mode VARCHAR(20) DEFAULT 'approve', -- approve | auto
  channels JSON DEFAULT ('["instagram","messenger"]'),
  brain TEXT, -- dedicated reply-bot instructions, separate from general Client Brain
  updated_by TEXT
) ENGINE=InnoDB;
CREATE INDEX idx_reply_bot_settings_client ON reply_bot_settings(client_id);

-- ----------------------------------------------------------------
-- SUBSCRIPTIONS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscriptions (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_id VARCHAR(36), client_name TEXT, client_email TEXT,
  service_name TEXT, description TEXT,
  amount DECIMAL(14,2), currency VARCHAR(10) DEFAULT 'EGP',
  billing_period TEXT DEFAULT ('monthly'),
  start_date TEXT, end_date TEXT,
  next_payment_date TEXT, last_payment_date TEXT,
  status VARCHAR(50) DEFAULT 'active', payment_method TEXT DEFAULT ('paymob'),
  paymob_token TEXT, paymob_payment_key TEXT, payment_link TEXT,
  cycle_count DECIMAL(14,0) DEFAULT 0, total_collected DECIMAL(14,2) DEFAULT 0,
  notes TEXT, created_by TEXT,
  reminder_7_sent TINYINT(1) DEFAULT 0, reminder_1_sent TINYINT(1) DEFAULT 0
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- SUBSCRIPTION PAYMENTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscription_payments (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  subscription_id VARCHAR(36) NOT NULL, subscription_name TEXT, client_name TEXT,
  amount DECIMAL(14,2), currency VARCHAR(10) DEFAULT 'EGP',
  method TEXT, payment_date TEXT, reference TEXT, notes TEXT,
  status VARCHAR(50) DEFAULT 'completed', cycle_number DECIMAL(14,0),
  paymob_transaction_id TEXT, invoice_id VARCHAR(36), collected_by TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- APP SETTINGS (singleton)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  setting_key VARCHAR(100) UNIQUE DEFAULT 'agency_settings',
  app_name TEXT DEFAULT ('SocialFlow'), app_logo_url MEDIUMTEXT,
  primary_color TEXT DEFAULT ('#d90b2c'),
  agency_email TEXT, agency_phone TEXT, agency_tagline TEXT, agency_website TEXT,
  default_currency VARCHAR(10) DEFAULT 'USD', default_language VARCHAR(10) DEFAULT 'en',
  tax_rate_default DECIMAL(6,2) DEFAULT 14,
  feature_flags JSON DEFAULT ('{}')
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- EMAIL SETTINGS (singleton)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_settings (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  setting_key VARCHAR(100) UNIQUE DEFAULT 'daily_performance_email',
  enabled TINYINT(1) DEFAULT 1, send_time TEXT DEFAULT ('18:00'),
  timezone TEXT DEFAULT ('Africa/Cairo'),
  subject TEXT, sender_name TEXT, sender_email TEXT,
  include_working_hours TINYINT(1) DEFAULT 1,
  include_completed_tasks TINYINT(1) DEFAULT 1,
  include_pending_tasks TINYINT(1) DEFAULT 1,
  include_overdue_tasks TINYINT(1) DEFAULT 1,
  include_metrics TINYINT(1) DEFAULT 1, include_motivation TINYINT(1) DEFAULT 1,
  attach_pdf TINYINT(1) DEFAULT 0, attach_format TEXT DEFAULT ('pdf'),
  last_sent_at TEXT, last_sent_count DECIMAL(14,0) DEFAULT 0,
  custom_footer TEXT, updated_by TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- BRANDING ASSETS (singleton)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS branding_assets (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  setting_key VARCHAR(100) UNIQUE DEFAULT 'agency_branding',
  primary_logo TEXT, secondary_logo TEXT, icon_logo TEXT,
  dark_logo TEXT, light_logo TEXT, watermark_logo TEXT,
  primary_color TEXT DEFAULT ('#d90b2c'), secondary_color TEXT DEFAULT ('#111122'),
  app_name TEXT DEFAULT ('SocialFlow'), agency_tagline TEXT,
  agency_website TEXT, agency_email TEXT, agency_phone TEXT,
  pdf_show_watermark TINYINT(1) DEFAULT 1, pdf_watermark_opacity DECIMAL(4,3) DEFAULT 0.06,
  updated_by TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- USER PROFILES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_profiles (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_email VARCHAR(255) UNIQUE NOT NULL,
  display_name TEXT, mobile TEXT, whatsapp_number TEXT, photo_url MEDIUMTEXT,
  wallpaper TEXT DEFAULT ('dark'), accent_color TEXT DEFAULT ('#d90b2c'),
  bio TEXT, language VARCHAR(10) DEFAULT 'en',
  notifications_email TINYINT(1) DEFAULT 1,
  notifications_browser TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CLIENT KNOWLEDGE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_knowledge (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_id VARCHAR(36), client_name TEXT,
  skills TEXT, tone TEXT, summary TEXT, keywords TEXT, priorities TEXT,
  industry_context TEXT, content_preferences TEXT,
  version DECIMAL(8,2) DEFAULT 1, last_analyzed TEXT,
  analyzed_by TEXT, sources_count DECIMAL(14,0) DEFAULT 0
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CLIENT DOCUMENTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_documents (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_id VARCHAR(36), client_name TEXT, name TEXT, content TEXT,
  doc_type TEXT DEFAULT ('notes'), analyzed TINYINT(1) DEFAULT 0,
  char_count DECIMAL(14,0), extracted_skills TEXT, uploaded_by TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- PERFORMANCE LOGS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS performance_logs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_email TEXT, user_name TEXT, role TEXT,
  post_id VARCHAR(36), post_title TEXT, project_id VARCHAR(36), client_name TEXT,
  stage_from TEXT, stage_to TEXT, duration_hours DECIMAL(14,2),
  completed_at TEXT, revision_count DECIMAL(14,0) DEFAULT 0,
  client_approved TINYINT(1) DEFAULT 0, rejected TINYINT(1) DEFAULT 0,
  on_time TINYINT(1) DEFAULT 1, quality_score DECIMAL(6,2),
  hour_of_day DECIMAL(4,0), day_of_week DECIMAL(4,0)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- AI INSIGHTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_insights (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  title TEXT, insight TEXT, category TEXT, action TEXT,
  priority TEXT DEFAULT ('medium'), is_read TINYINT(1) DEFAULT 0,
  generated_at TEXT, related_user TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- TIME ENTRIES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS time_entries (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  post_id VARCHAR(36), post_title TEXT, user_email TEXT, user_name TEXT,
  date TEXT, started_at TEXT, paused_at TEXT,
  status VARCHAR(50) DEFAULT 'active', total_seconds DECIMAL(14,2) DEFAULT 0,
  project_id VARCHAR(36), notes TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- SCHEDULE OVERRIDES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schedule_overrides (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  post_id VARCHAR(36), post_title TEXT, user_email TEXT,
  date TEXT, start_mins DECIMAL(14,2), overridden_by TEXT, reason TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CLIENT CONTRACTS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_contracts (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_id VARCHAR(36), client_name TEXT, allowed_task_types TEXT,
  billing_cycle_start DECIMAL(4,0), limits_enabled TINYINT(1) DEFAULT 0,
  notes TEXT, task_limits TEXT, created_by TEXT, updated_by TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CLIENT INTELLIGENCE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_intelligence (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  client_id VARCHAR(36) UNIQUE NOT NULL, client_name TEXT,
  preferred_platforms JSON DEFAULT ('[]'),
  best_posting_days JSON DEFAULT ('[]'),
  avoid_weekends TINYINT(1) DEFAULT 0,
  posting_frequency DECIMAL(6,2) DEFAULT 3,
  instagram_best_time TEXT DEFAULT ('18:00'),
  facebook_best_time TEXT DEFAULT ('12:00'),
  tiktok_best_time TEXT DEFAULT ('19:00'),
  linkedin_best_time TEXT DEFAULT ('09:00'),
  active_hours TEXT DEFAULT ('evening'),
  timezone TEXT DEFAULT ('Africa/Cairo'),
  peak_engagement_days JSON DEFAULT ('[]'),
  preferred_content_types JSON DEFAULT ('[]'),
  preferred_pillars JSON DEFAULT ('[]'),
  best_performing_type TEXT,
  best_performing_day TEXT,
  avg_engagement_rate DECIMAL(8,4),
  updated_by TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CONTENT PILLARS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_pillars (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  project_id VARCHAR(36) NOT NULL, pillar_name TEXT NOT NULL,
  post_count DECIMAL(6,0) DEFAULT 1, post_type TEXT DEFAULT ('static'),
  description TEXT, assigned_to TEXT, platform TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- USER INVITATIONS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_invitations (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  email TEXT NOT NULL, name TEXT, role TEXT NOT NULL,
  permissions TEXT, token VARCHAR(255) UNIQUE NOT NULL,
  expires_at TIMESTAMP NOT NULL, status VARCHAR(50) DEFAULT 'pending',
  invited_by TEXT, user_type TEXT DEFAULT ('internal'),
  client_id VARCHAR(36), client_name TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- ACCESS REQUESTS (self-signup)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS access_requests (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name TEXT NOT NULL, email TEXT NOT NULL, password_hint TEXT,
  requested_role TEXT, status VARCHAR(50) DEFAULT 'pending',
  user_type TEXT DEFAULT ('internal'), company_name TEXT,
  client_id VARCHAR(36), client_name TEXT, message TEXT,
  reviewed_by TEXT, reviewed_at TEXT, rejection_reason TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CLIENT USERS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_users (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_id VARCHAR(36) NOT NULL, client_name TEXT,
  email TEXT NOT NULL, name TEXT NOT NULL,
  role TEXT DEFAULT ('client_member'), status VARCHAR(50) DEFAULT 'invited',
  photo_url MEDIUMTEXT, mobile TEXT, last_login TEXT, password TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CLIENT TASKS  (client_id is UUID in source — kept as VARCHAR(36) FK-style)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_tasks (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_id VARCHAR(36), client_name TEXT,
  title TEXT, description TEXT, task_type TEXT,
  priority TEXT DEFAULT ('medium'), stage TEXT DEFAULT ('new_request'),
  assigned_to TEXT, created_by TEXT, deliverable_note TEXT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- CLIENT MEMORY  (client_id is UUID in source — kept as VARCHAR(36))
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_memory (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  client_id VARCHAR(36), client_name TEXT,
  `key` TEXT, value TEXT, type TEXT DEFAULT ('manual')
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- EMAIL LOGS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_logs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `to` TEXT, subject TEXT, from_name TEXT,
  status VARCHAR(50) DEFAULT 'sent', error_message TEXT,
  sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- PUSH SUBSCRIPTIONS (Web Push — task-assignment alerts, works closed)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_email VARCHAR(255) NOT NULL,
  endpoint VARCHAR(512) NOT NULL, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
  UNIQUE KEY uq_push_subscriptions_endpoint (endpoint)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- ACTIVITY LOGS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  action TEXT, category TEXT, details TEXT,
  status VARCHAR(50) DEFAULT 'success', error_message TEXT,
  performed_by TEXT, performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- STUB TABLES — referenced by app.jsx's qe/ce/ue/de calls but with no
-- corresponding table in the live Supabase database (confirmed dead/未-wired
-- features: lead generation agent, AI agent config/run logging, monthly
-- briefs). qe() fails silently against a missing table, so these features
-- currently render with empty data. Created here as real, empty tables so
-- the same code paths return [] instead of erroring once Supabase's
-- "missing relation" 404s are no longer there to hide behind. Drop these if
-- you decide not to pursue agent/monthly-brief features.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS generated_leads (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data JSON DEFAULT ('{}')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lead_agent_configs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data JSON DEFAULT ('{}')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agent_configs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data JSON DEFAULT ('{}')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agent_logs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data JSON DEFAULT ('{}')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agent_runs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data JSON DEFAULT ('{}')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_sessions (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_email TEXT, user_name TEXT, user_role VARCHAR(50),
  ip_address VARCHAR(64), country TEXT, country_code VARCHAR(10),
  region TEXT, city TEXT, isp TEXT, org TEXT,
  latitude DOUBLE, longitude DOUBLE,
  browser TEXT, os TEXT, device_type VARCHAR(20),
  screen_resolution VARCHAR(20), viewport VARCHAR(20),
  timezone VARCHAR(64), language VARCHAR(20),
  user_agent TEXT, login_at TIMESTAMP NULL, page_url TEXT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS monthly_briefs (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  data JSON DEFAULT ('{}')
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- META INSIGHTS SNAPSHOTS — daily Page/IG/Ads metrics per integration,
-- populated by meta-insights-cron.php, so the AI analysis has a real
-- trend to learn from instead of just a single day's numbers.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meta_insights_snapshots (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  integration_id VARCHAR(36) NOT NULL, client_id VARCHAR(36), client_name TEXT,
  platform TEXT, snapshot_date DATE NOT NULL,
  metrics JSON DEFAULT ('{}')
) ENGINE=InnoDB;

-- ----------------------------------------------------------------
-- Helpful indexes for the most common filters used by app.jsx
-- ----------------------------------------------------------------
CREATE INDEX idx_posts_project_id ON posts(project_id);
CREATE INDEX idx_posts_client_id ON posts(client_id);
CREATE INDEX idx_posts_stage ON posts(stage);
CREATE INDEX idx_projects_client_id ON projects(client_id);
CREATE INDEX idx_comments_post_id ON comments(post_id);
CREATE INDEX idx_assets_project_id ON assets(project_id);
CREATE INDEX idx_invoices_client_id ON invoices(client_id);
CREATE INDEX idx_payments_invoice_id ON payments(invoice_id);
CREATE INDEX idx_quotes_client_id ON quotes(client_id);
CREATE INDEX idx_leads_status ON leads(status);
CREATE INDEX idx_lead_activities_lead_id ON lead_activities(lead_id);
CREATE INDEX idx_subscriptions_client_id ON subscriptions(client_id);
CREATE INDEX idx_subscription_payments_subscription_id ON subscription_payments(subscription_id);
CREATE INDEX idx_client_tasks_client_id ON client_tasks(client_id);
CREATE INDEX idx_client_memory_client_id ON client_memory(client_id);
CREATE INDEX idx_client_users_client_id ON client_users(client_id);
CREATE INDEX idx_client_documents_client_id ON client_documents(client_id);
CREATE INDEX idx_client_knowledge_client_id ON client_knowledge(client_id);
CREATE INDEX idx_notifications_recipient ON notifications(recipient_email(191));
CREATE INDEX idx_integration_logs_integration_id ON integration_logs(integration_id);
CREATE INDEX idx_performance_logs_user_email ON performance_logs(user_email(191));
CREATE INDEX idx_time_entries_user_email ON time_entries(user_email(191));
CREATE INDEX idx_push_subscriptions_user_email ON push_subscriptions(user_email);
