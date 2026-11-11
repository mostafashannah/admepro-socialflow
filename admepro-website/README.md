# admepro.com: new static website (TEST PHASE)

A fast, bilingual (English + Arabic) rebuild of the current WordPress site at admepro.com.

**Status:** test project. It lives only in this folder, on the `admepro-website` branch.
- It is **not** connected to SocialFlow. Forms run in stub mode and send nothing.
- It is **not** live. Nothing on admepro.com or WordPress has been changed.
- Do not merge this branch into `main`, and do not edit any file outside `admepro-website/`.

## What's inside

| Path | What it is |
|---|---|
| `src/content/site.json` | Contact details, offices, menu, all interface text (EN + AR) |
| `src/content/shared.json` | Blocks used on several pages: services, testimonials, stats, FAQ, client logos |
| `src/content/pages/*.json` | Copy for Home, About, Contact, the 4 service pages, GCC, Portfolio/Blog headings |
| `src/content/projects/<slug>/` | **One folder per project**: `project.json` + `images/` (see [ADDING-A-PROJECT.md](ADDING-A-PROJECT.md)) |
| `src/content/posts/*.json` | Blog posts (English) |
| `src/content/tags.json` | Project tags with Arabic names |
| `src/content/redirects.json` | 301 redirects from old WordPress URLs |
| `src/assets/` | CSS, JS (~11 KB), self-hosted fonts, logos, site images, videos |
| `build/build.mjs` | The generator (Node 18+, no npm install needed) |
| `build/serve.mjs` | Local preview server |
| `deploy/` | Server configs for the test subdomain (see [DEPLOY.md](DEPLOY.md)) |
| `_crawl/` | Snapshot of the old site: page text, screenshots, styles, old URL list |
| `CRAWL-REPORT.md` | Step 1 report: page list and design summary |

## Commands

```bash
cd admepro-website
npm run build        # → dist/  (fails loudly on broken links, missing images, bad JSON)
npm run serve        # → http://localhost:4321  and  http://localhost:4321/ar/
npm run preview      # build + serve
npm run build:staging   # test build for new.admepro.com (noindex + "TEST SITE" badge)
npm run new-project -- <slug> "<Title>" --from <folder of photos>
```

## Pages generated (58)

The English pages keep the **same URLs as today**, so Google rankings carry over. Arabic pages live under `/ar/`.

- `/`, `/about/`, `/portfolio/`, `/contact-us/`
- the 4 service pages: `/branding/`, `/creative/`, `/digital/`, `/production/`
- `/gcc-ksa-ai-marketing-agency/`
- `/blog/`
- `/project/<slug>/` (14 projects)
- `/blog/<slug>/` (8 posts, English only; the Arabic blog page lists them and labels them as English)
- `404.html`, `sitemap.xml`, `robots.txt`, `.htaccess`, `_redirects`, `api/projects.json`

## Improvements over the WordPress site

- **Speed:** no WordPress or plugins and about 11 KB of JS. Fonts are self-hosted. Images are WebP (18 MB in total, down from 118 MB) and load lazily with fixed sizes, so the layout doesn't jump. Vimeo only loads when someone presses play. Assets have cache-busting names. The home page is about 0.7 MB on first load, against 2 MB or more today.
- **Mobile:** a real mobile menu, a card grid for the portfolio, and the chat pill no longer covers the hero.
- **Arabic + English:** a full RTL layout with the IBM Plex Sans Arabic font, a language switch on every page, and hreflang tags.
- **SEO:**
  - unique titles and descriptions, canonical URLs, and Open Graph/Twitter tags
  - schema.org data: Organization with all 3 offices, WebSite, Service, CreativeWork, BlogPosting, FAQPage and BreadcrumbList
  - a sitemap with hreflang and image entries
  - 301 redirects for every old URL (`src/content/redirects.json`, plus automatic rules for portfolio categories and tags)
- **Forms:** a quote form (contact page) and a "book a call" form (home and GCC). They have validation, spam protection and UTM capture, and are ready for SocialFlow (see [INTEGRATION.md](INTEGRATION.md)).
- **Accessibility:** a skip link, keyboard-friendly menus and accordions, alt text, and reduced-motion support.

## Content notes for the owner

- **GCC page:** the live version still contains theme demo text and images. It was rewritten with real admepro copy and images.
- **Text I rewrote:** the "Brand naming" service text was website-design copy pasted by mistake, so I rewrote it.
- **Zen colour codes:** these were duplicated on the live page, so I corrected them to the official RAL codes for the colour names shown.
- **Riyadh office:** it now shows info@admepro.com. Office hours no longer say "EST".
- **Arabic text:** drafted by Claude, so please have a native speaker review it before launch.
- **Stock photos:** a few came with the WordPress theme (`src/assets/img/site/stock/`, used on About, the services pages and GCC). Check the licence or replace them with your own studio photos before going live.
