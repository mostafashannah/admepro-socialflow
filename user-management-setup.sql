-- User Invitations
create table if not exists user_invitations (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  email text not null,
  name text,
  role text not null,
  permissions text,
  token text unique not null,
  expires_at timestamptz not null,
  status text default 'pending',
  invited_by text,
  user_type text default 'internal',
  client_id text,
  client_name text
);

-- Access Requests (self-signup)
create table if not exists access_requests (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  name text not null,
  email text not null,
  password_hint text,
  requested_role text,
  status text default 'pending',
  user_type text default 'internal',
  company_name text,
  client_id text,
  client_name text,
  message text,
  reviewed_by text,
  reviewed_at text,
  rejection_reason text
);

-- Client Users
create table if not exists client_users (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz default now(),
  client_id text not null,
  client_name text,
  email text not null,
  name text not null,
  role text default 'client_member',
  status text default 'invited',
  photo_url text,
  mobile text,
  last_login text,
  password text
);

-- RLS: allow all for anon key
alter table user_invitations enable row level security;
alter table access_requests enable row level security;
alter table client_users enable row level security;

drop policy if exists allow_all on user_invitations;
drop policy if exists allow_all on access_requests;
drop policy if exists allow_all on client_users;

create policy allow_all on user_invitations for all using (true) with check (true);
create policy allow_all on access_requests for all using (true) with check (true);
create policy allow_all on client_users for all using (true) with check (true);
