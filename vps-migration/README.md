# Migrating SocialFlow off Supabase onto your own VPS MySQL backend

This replaces Supabase (hosted Postgres + Storage) with a MySQL database and a
small PHP REST API that speaks the same wire protocol `app.jsx` already uses,
so the ~20,000 lines of UI/business logic don't need to change — only 3
constants at the top of `app.jsx` do.

## Why this works
Every Supabase call in `app.jsx` goes through exactly 4 functions: `qe()`
(list), `ce()` (create), `ue()` (update), `de()` (delete). They build
PostgREST-style URLs like:

```
GET    {SB_URL}/posts?select=*&client_id=eq.123&order=created_at.desc&limit=50
POST   {SB_URL}/posts                  (Prefer: return=representation)
PATCH  {SB_URL}/posts?id=eq.123        (Prefer: return=representation)
DELETE {SB_URL}/posts?id=eq.123
```

`api.php` in this folder implements the same conventions against MySQL.

## Setup steps on the VPS

1. **Create the database**
   ```
   mysql -u root -p < vps-migration/mysql-schema.sql
   ```
   This creates the `socialflow` database and all 45 tables (38 real Supabase
   tables + 7 empty stub tables for features that were referenced in the
   code but never had a live Supabase table — see the comment block in
   `mysql-schema.sql` above the stub tables).

2. **Create an app-only MySQL user** (don't use root from PHP):
   ```sql
   CREATE USER 'socialflow_app'@'localhost' IDENTIFIED BY 'CHANGE_ME';
   GRANT SELECT, INSERT, UPDATE, DELETE ON socialflow.* TO 'socialflow_app'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. **Copy `config.example.php` → `config.php`** (project root) and fill in:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — match step 2
   - `API_KEY` — generate with `openssl rand -hex 32`
   - `STORAGE_ROOT` / `STORAGE_PUBLIC_URL` — where uploaded files live

4. **Copy `api.php` and `storage.php`** from this folder to the project root
   (same level as `index.html`, `app.js`). Create the uploads directory:
   ```
   mkdir -p uploads && chmod 755 uploads
   ```

5. **Wire up the rewrite rules**
   - Nginx: copy the `location` blocks from `nginx-rewrite.conf.example`
     into your server block, then `nginx -t && systemctl reload nginx`.
   - Apache: rename `.htaccess.example` to `.htaccess` at the project root
     (merge with any existing `.htaccess` for the SPA redirect).

6. **Update `app.jsx`** — change the 3 constants near the top:
   ```js
   const SB_URL = "https://yourdomain.com/api";
   const SB_KEY = "<the same value you put in API_KEY in config.php>";
   const SB_STORAGE_URL = "https://yourdomain.com/storage";
   ```
   Then recompile: `npx babel app.jsx -o app.js`

7. **Migrate existing data from Supabase** (optional, if you have real data
   to keep — skip if starting fresh):
   ```
   php migrate-from-supabase.php
   ```
   This pulls every row from all 38 real tables out of the original Supabase
   project (via its REST API, using the old anon key already in the script)
   and inserts it into the local MySQL database, matching columns by name.
   Safe to re-run — existing rows (same `id`) are skipped via `INSERT IGNORE`,
   so re-running only picks up anything added to Supabase since the last run.
   Note: this migrates table rows only, not Supabase Storage files — any
   `assets`/`client_documents`/`branding_assets` rows that reference a
   Supabase Storage path will need those files re-uploaded to `STORAGE_ROOT`
   separately (the row's path will still point at the old Supabase bucket
   until you do).

8. **Smoke test** before pointing the live domain at it:
   ```
   curl -H "apikey: $API_KEY" "https://yourdomain.com/api/clients?select=*&limit=1"
   ```
   Should return `[]` (empty array) on a fresh database, not an error.

## What's intentionally NOT migrated automatically
- Row Level Security: Supabase's `allow_all` RLS policies become a single
  shared `API_KEY` check in `api.php`. If you need per-role restrictions later,
  add them inside `api.php` (it already knows the table name and HTTP method).
- The 7 stub tables (`generated_leads`, `lead_agent_configs`, `agent_configs`,
  `agent_logs`, `agent_runs`, `system_sessions`, `monthly_briefs`) are created
  empty. They were referenced by `app.jsx`'s entity map but had no matching
  table in the live Supabase project, so those features were already
  rendering empty data. Drop them from `mysql-schema.sql` if you don't plan
  to build those features.
