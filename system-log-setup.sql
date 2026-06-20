-- ================================================================
-- SocialFlow — System Log tables (Activity Log + Session/Login Log)
-- Run this once in the Supabase SQL editor. These tables were never
-- created, which is why System Log has always appeared empty.
-- ================================================================

-- ACTIVITY LOG — every create/update/delete action across the app
create table if not exists activity_logs (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  action text, category text, details text,
  status text default 'success', error_message text,
  performed_by text, performed_at timestamptz default now()
);

-- SYSTEM SESSIONS — login sessions with device/IP/geo info
create table if not exists system_sessions (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  user_email text, user_name text, user_role text,
  ip_address text, country text, country_code text, region text, city text,
  isp text, org text, latitude numeric, longitude numeric,
  browser text, os text, device_type text,
  screen_resolution text, viewport text, timezone text, language text,
  user_agent text, login_at timestamptz default now(), page_url text
);

-- ================================================================
-- ROW LEVEL SECURITY — allow all for anon key (app handles auth)
-- ================================================================
alter table activity_logs enable row level security;
drop policy if exists allow_all on activity_logs;
create policy allow_all on activity_logs for all using (true) with check (true);

alter table system_sessions enable row level security;
drop policy if exists allow_all on system_sessions;
create policy allow_all on system_sessions for all using (true) with check (true);
