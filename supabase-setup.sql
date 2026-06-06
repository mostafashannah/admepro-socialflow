-- ================================================================
-- SocialFlow — Supabase Database Setup
-- Run this ONCE in: Supabase Dashboard → SQL Editor → New Query
-- ================================================================

-- POSTS
create table if not exists posts (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  project_id text, client_id text, client_name text,
  title text not null, description text, stage text default 'planning',
  platform text, post_type text, caption text, hashtags text,
  design_urls jsonb default '[]', scheduled_date text, scheduled_time text,
  assigned_to text, priority text default 'medium', rejection_reason text
);

-- PROJECTS
create table if not exists projects (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  title text not null, description text,
  client_id text, client_name text not null,
  status text default 'active',
  start_date text, end_date text,
  platforms jsonb default '[]', team_members jsonb default '[]'
);

-- CLIENTS
create table if not exists clients (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text not null, email text not null, phone text, logo_url text,
  industry text, status text default 'active', account_manager_id text,
  notes text, platforms jsonb default '[]', portal_password text
);

-- TEAM MEMBERS
create table if not exists team_members (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text not null, email text not null, role text default 'content_creator',
  status text default 'active', avatar_url text, department text,
  permissions text, password text
);

-- COMMENTS
create table if not exists comments (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  post_id text not null, content text not null,
  author_name text, author_email text, type text default 'comment',
  mentions jsonb default '[]'
);

-- ASSETS
create table if not exists assets (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text not null, file_url text not null, file_type text,
  category text, tags jsonb default '[]', project_id text,
  description text, file_size numeric, thumbnail_url text
);

-- TIME LOGS
create table if not exists time_logs (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  project_id text, task_name text, duration_minutes numeric,
  notes text, logged_by text, log_date text
);

-- NOTIFICATIONS
create table if not exists notifications (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  recipient_email text not null, title text not null, message text,
  type text default 'info', is_read boolean default false,
  link_id text, link_type text
);

-- TEMPLATES
create table if not exists templates (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text not null, platform text, post_type text,
  caption_template text, hashtags text, category text,
  description text, thumbnail_url text
);

-- QUOTES
create table if not exists quotes (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  quote_number text, title text, status text default 'draft',
  client_id text, client_name text, client_phone text, client_email text,
  date text, due_date text, items text,
  subtotal numeric, discount_type text default 'percent',
  discount_value numeric default 0, tax_rate numeric default 0,
  total numeric, currency text default 'USD',
  payment_terms text, notes text, created_by text, pdf_url text
);

-- LEADS
create table if not exists leads (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text not null, company text, phone text, email text,
  source text default 'website', status text default 'new',
  assigned_to text, followup_date text, value numeric,
  currency text default 'USD', platforms jsonb default '[]',
  notes text, converted boolean default false,
  client_id text, tags jsonb default '[]'
);

-- LEAD ACTIVITIES
create table if not exists lead_activities (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  lead_id text not null, content text not null,
  author_name text, author_email text,
  type text default 'note', followup_date text
);

-- INVOICES
create table if not exists invoices (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  invoice_number text, quote_id text,
  client_id text, client_name text, client_email text, client_phone text,
  title text, issue_date text, due_date text, currency text default 'USD',
  items text, subtotal numeric,
  discount_value numeric default 0, discount_type text default 'percent',
  tax_rate numeric default 0, total numeric,
  amount_paid numeric default 0, balance_due numeric,
  status text default 'unpaid', payment_terms text,
  notes text, created_by text, sent_at text
);

-- PAYMENTS
create table if not exists payments (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  invoice_id text not null, invoice_number text, amount numeric,
  method text default 'bank', payment_date text, reference text,
  notes text, confirmed_by text, recorded_by text
);

-- INTEGRATIONS
create table if not exists integrations (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text not null, app_key text not null, app_category text,
  trigger text, action text, status text default 'inactive',
  credentials text, config text, webhook_url text,
  last_run_at text, last_run_status text default 'never',
  last_run_message text, run_count numeric default 0, error_count numeric default 0,
  created_by text, icon_url text, template_id text
);

-- INTEGRATION LOGS
create table if not exists integration_logs (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  integration_id text not null, integration_name text,
  status text, duration_ms numeric, error text,
  payload_summary text, triggered_by text
);

-- SUBSCRIPTIONS
create table if not exists subscriptions (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  client_id text, client_name text, client_email text,
  service_name text, description text,
  amount numeric, currency text default 'EGP',
  billing_period text default 'monthly',
  start_date text, end_date text,
  next_payment_date text, last_payment_date text,
  status text default 'active', payment_method text default 'paymob',
  paymob_token text, paymob_payment_key text, payment_link text,
  cycle_count numeric default 0, total_collected numeric default 0,
  notes text, created_by text,
  reminder_7_sent boolean default false, reminder_1_sent boolean default false
);

-- SUBSCRIPTION PAYMENTS
create table if not exists subscription_payments (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  subscription_id text not null, subscription_name text, client_name text,
  amount numeric, currency text default 'EGP',
  method text, payment_date text, reference text, notes text,
  status text default 'completed', cycle_number numeric,
  paymob_transaction_id text, invoice_id text, collected_by text
);

-- APP SETTINGS (singleton)
create table if not exists app_settings (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  setting_key text unique default 'agency_settings',
  app_name text default 'SocialFlow', app_logo_url text,
  primary_color text default '#d90b2c',
  agency_email text, agency_phone text, agency_tagline text, agency_website text,
  default_currency text default 'USD', default_language text default 'en',
  tax_rate_default numeric default 14
);

-- EMAIL SETTINGS (singleton)
create table if not exists email_settings (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  setting_key text unique default 'daily_performance_email',
  enabled boolean default true, send_time text default '18:00',
  timezone text default 'Africa/Cairo',
  subject text, sender_name text, sender_email text,
  include_working_hours boolean default true,
  include_completed_tasks boolean default true,
  include_pending_tasks boolean default true,
  include_overdue_tasks boolean default true,
  include_metrics boolean default true, include_motivation boolean default true,
  attach_pdf boolean default false, attach_format text default 'pdf',
  last_sent_at text, last_sent_count numeric default 0,
  custom_footer text, updated_by text
);

-- BRANDING ASSETS (singleton)
create table if not exists branding_assets (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  setting_key text unique default 'agency_branding',
  primary_logo text, secondary_logo text, icon_logo text,
  dark_logo text, light_logo text, watermark_logo text,
  primary_color text default '#d90b2c', secondary_color text default '#111122',
  app_name text default 'SocialFlow', agency_tagline text,
  agency_website text, agency_email text, agency_phone text,
  pdf_show_watermark boolean default true, pdf_watermark_opacity numeric default 0.06,
  updated_by text
);

-- USER PROFILES
create table if not exists user_profiles (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  user_email text unique not null,
  display_name text, mobile text, photo_url text,
  wallpaper text default 'dark', accent_color text default '#d90b2c',
  bio text, language text default 'en',
  notifications_email boolean default true,
  notifications_browser boolean default true
);

-- CLIENT KNOWLEDGE
create table if not exists client_knowledge (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  client_id text, client_name text,
  skills text, tone text, summary text, keywords text, priorities text,
  industry_context text, content_preferences text,
  version numeric default 1, last_analyzed text,
  analyzed_by text, sources_count numeric default 0
);

-- CLIENT DOCUMENTS
create table if not exists client_documents (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  client_id text, client_name text, name text, content text,
  doc_type text default 'notes', analyzed boolean default false,
  char_count numeric, extracted_skills text, uploaded_by text
);

-- PERFORMANCE LOGS
create table if not exists performance_logs (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  user_email text, user_name text, role text,
  post_id text, post_title text, project_id text, client_name text,
  stage_from text, stage_to text, duration_hours numeric,
  completed_at text, revision_count numeric default 0,
  client_approved boolean default false, rejected boolean default false,
  on_time boolean default true, quality_score numeric,
  hour_of_day numeric, day_of_week numeric
);

-- AI INSIGHTS
create table if not exists ai_insights (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  title text, insight text, category text, action text,
  priority text default 'medium', is_read boolean default false,
  generated_at text, related_user text
);

-- TIME ENTRIES
create table if not exists time_entries (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  post_id text, post_title text, user_email text, user_name text,
  date text, started_at text, paused_at text,
  status text default 'active', total_seconds numeric default 0,
  project_id text, notes text
);

-- SCHEDULE OVERRIDES
create table if not exists schedule_overrides (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  post_id text, post_title text, user_email text,
  date text, start_mins numeric, overridden_by text, reason text
);

-- CLIENT CONTRACTS
create table if not exists client_contracts (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  client_id text, client_name text, allowed_task_types text,
  billing_cycle_start numeric, limits_enabled boolean default false,
  notes text, task_limits text, created_by text, updated_by text
);

-- ================================================================
-- ROW LEVEL SECURITY — allow all for anon key (app handles auth)
-- ================================================================
do $$ declare t text; begin
  for t in select tablename from pg_tables where schemaname = 'public' loop
    execute format('alter table %I enable row level security', t);
    execute format('drop policy if exists allow_all on %I', t);
    execute format('create policy allow_all on %I for all using (true) with check (true)', t);
  end loop;
end $$;
