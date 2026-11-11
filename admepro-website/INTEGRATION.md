# SocialFlow integration (designed, not connected)

There is **no live connection** to SocialFlow yet. The website makes no calls to the SocialFlow API and never writes to its database. Two integration points are prepared and clearly marked as stubs.

## 1. Website lead forms → SocialFlow `leads`

**Where:**
- the quote form on `/contact-us/` (`data-lead-form="quote"`)
- the "Book a Call" form on `/` and `/gcc-ksa-ai-marketing-agency/` (`data-lead-form="call"`)

The code is in `src/assets/js/site.js` (section "lead forms").

**Today (stub mode):** nothing leaves the browser. On submit, the lead is:
- validated
- logged to the browser console
- kept in `localStorage["admepro_test_leads"]` (the last 20)

The visitor sees "Thank you… (Test mode: nothing was sent.)".

**Payload:** this is the shape the site already builds. It mirrors SocialFlow's `leads` table (`name, company, phone, email, source, status, notes, tags`):
```json
{
  "name": "Sara Ali", "company": "Acme", "email": "sara@acme.com", "phone": "+20 100 000 0000",
  "source": "website", "status": "new",
  "tags": ["website", "form:quote", "lang:ar", "service:branding"],
  "notes": "Project details…\nService: Branding\nBudget (USD): 5K – 10K\nPage: /ar/contact-us/",
  "meta": { "form": "quote", "lang": "ar", "page": "https://…", "referrer": "…",
            "utm": { "utm_source": "facebook", "utm_campaign": "…" }, "submittedAt": "2026-11-11T10:00:00Z",
            "service": "branding", "budget": "5k-10k", "bestTime": "" }
}
```
In live mode the browser sends `multipart/form-data`: the JSON goes in a `lead` field, plus an optional `brief` file.

**To go live later (needs the owner's approval):**
1. **Add an endpoint to SocialFlow.** For example, a new `website-lead.php` that:
   - accepts POST requests only from `https://admepro.com` and the test subdomain (CORS allow-list)
   - rate-limits per IP and rejects requests where the `website` honeypot field is filled
   - validates the fields and inserts **one row** into `leads` (`source='website'`, `status='new'`, `notes`, `tags` as JSON)
   - saves `meta` as a `lead_activities` note, and stores `brief` with the existing storage helpers
   - responds `200 {"ok":true}`

   No API key goes in the website code, because the endpoint is public by design.
2. Rebuild the site with the URL: `LEAD_ENDPOINT=https://socialflow.admepro.com/website-lead.php npm run build`, or set `"forms": { "endpoint": "…" }` in `src/content/site.json`.
3. Test on the test subdomain first, then on production.

## 2. Projects / portfolio feed

**Today:** every build writes a public, read-only feed to `dist/api/projects.json`. It lists each project's slug, title, client, categories, tags, cover URL, EN/AR URLs and summary. SocialFlow (or anything else) can read it to show "latest work" or to link client records to their case studies. Nothing needs to be called for this to work.

**Later, optional:** let SocialFlow publish projects. SocialFlow would export a project as `src/content/projects/<slug>/project.json` plus images, using the same schema as [ADDING-A-PROJECT.md](ADDING-A-PROJECT.md), then commit it to this branch or trigger a rebuild. Keep the website as the source of truth until that flow is agreed.

## 3. Careers

Today, "See open positions" and every old `/job/...` URL point to `https://socialflow.admepro.com/careers`, the existing SocialFlow careers page. This was already the case on the WordPress site.
