-- Client Intelligence (strategic data per client)
create table if not exists client_intelligence (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  updated_at timestamptz default now(),
  client_id text unique not null,
  client_name text,
  -- Posting Strategy
  preferred_platforms jsonb default '[]',
  best_posting_days jsonb default '[]',
  avoid_weekends boolean default false,
  posting_frequency numeric default 3,
  -- Best Posting Times
  instagram_best_time text default '18:00',
  facebook_best_time text default '12:00',
  tiktok_best_time text default '19:00',
  linkedin_best_time text default '09:00',
  -- Audience Behavior
  active_hours text default 'evening',
  timezone text default 'Africa/Cairo',
  peak_engagement_days jsonb default '[]',
  -- Content Preferences
  preferred_content_types jsonb default '[]',
  preferred_pillars jsonb default '[]',
  -- Performance (prepared structure)
  best_performing_type text,
  best_performing_day text,
  avg_engagement_rate numeric,
  updated_by text
);

-- Content Pillars (rows in wizard step 2)
create table if not exists content_pillars (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  project_id text not null,
  pillar_name text not null,
  post_count numeric default 1,
  post_type text default 'static',
  description text,
  assigned_to text,
  platform text
);

-- RLS
alter table client_intelligence enable row level security;
alter table content_pillars enable row level security;
drop policy if exists allow_all on client_intelligence;
drop policy if exists allow_all on content_pillars;
create policy allow_all on client_intelligence for all using (true) with check (true);
create policy allow_all on content_pillars for all using (true) with check (true);
