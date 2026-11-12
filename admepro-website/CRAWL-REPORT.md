# admepro.com: crawl report (step 1)

Crawled 2026-11-11, read-only, from `sitemap.xml` (All in One SEO), header menu and footer.
WordPress theme: **Ohio** (Elementor). 77 public URLs, 181 images, 7 videos.

Raw data in this folder:
- `_crawl/pages/<slug>.json`: per page: title, meta description, nav, footer, and content in order (headings, text, images, buttons)
- `_crawl/screenshots/`: desktop + mobile captures of the main page types
- `_crawl/computed-styles.json`: real fonts, sizes and colors read from the browser
- `_crawl/old-urls.txt`: every old URL (for the redirect map)
- `_crawl/videos.txt`: 7 MP4s (not downloaded yet, see "Open questions")
- `assets/img/uploads/`: all images as WebP (18 MB, from 118 MB of originals); `_manifest.json` maps each to its original URL
- `assets/img/brand/`: logos (P. mark, admepro• wordmark) and favicons

## Pages

### Main pages (rebuild 1:1)
| Old URL | Page | Notes |
|---|---|---|
| `/` | Home | Red hero, client logo marquee, "What we do", 4 service accordions, Work grid (10), "Revenue systems" 4 cards, Featured ASUS, testimonials slider, dark stats band (12+ / 7 / 100+ / 1000+), team image cards, contact form + FAQ, CTA, footer |
| `/about/` | About | Giant "admepro." H1, 4 services, team (Founder & CEO, Co-founder), numbers, studios + 3 accordions, client logo grid |
| `/portfolio/` | Portfolio | Filter (All 14 / Branding 5 / Creative 8 / Digital 5 / Production 8), big text list with hover image |
| `/contact-us/` | Contact | 3 offices (Cairo, Dubai, Riyadh) + map, quote form (name, company, email, phone, subject, budget, details, brief upload), careers CTA |
| `/branding/` `/creative/` `/digital/` `/production/` | 4 service pages | Same template: title, image, service accordion list, vertical work samples, testimonial, stats, 4 CTAs, logos |
| `/gcc-ksa-ai-marketing-agency/` | GCC / KSA landing | Long landing page |
| `/digital-marketing-agency-egypt-arabic/` | Arabic landing | Only Arabic page today |
| `/blog-page/` | Blog/careers list | Card grid (title says "Careers", shows blog posts) |

### Projects (14), `/project/<slug>/`
Cote, Kohi Bar, MCM, Zen, Asus Zenfone, TMG NOOR, Golds Gym, Autogate, Skycorp, Elle, Tablya, Moltobella, Pescado, Verdi.
Template: full-screen hero image + title, intro + meta (Strategy / Design / Client / Tags), numbered story sections with videos or galleries, credits ("people behind the magic"), CTA.

### Blog posts (8), `/blog/<slug>/`
Social media agency Egypt · AI content production · Video production Cairo · Egypt to KSA/UAE · Performance marketing ROI · AI marketing 3x · Real estate marketing · Choosing an agency.

### Jobs (4), `/job/<slug>/`
Business Developer, Account Executive, Account Manager, Senior Account Manager. The footer "See open positions" already points to `socialflow.admepro.com/careers`.

### Archive pages (generated, won't be hand-built)
4 portfolio categories, 22 portfolio tags, 6 blog categories, 4 post tags. These become filters or redirects.

### Leftovers (plan: redirect, don't rebuild)
`/homeold/`, `/portfolio-1/`, `/job-description/`, `/job/`, WooCommerce `/shop/ /cart/ /checkout/ /my-account/`.

## Design summary
- **Look:** clean, editorial, lots of white space, big tight headlines. Bright red only for the hero and buttons; dark charcoal cards for contrast.
- **Fonts:** headings **DM Sans** 600 (H1 ~69px, tight letter-spacing −3px; H2 ~49px); body uses the system font stack at 16.4px / 1.6. Arabic will need a matching font (proposal: IBM Plex Sans Arabic or Cairo).
- **Colors:**
  - Red `#D90A2C` (hero, Get a Quote, accents)
  - Ink `#111013` / `#161518` (text, dark sections)
  - Grey `#7D7C80` (secondary text), cream `#F2EFE4` (hero text), light grey `#F9F9F9` (footer and form fields)
- **Layout:** about 1300px content width, 12-col grid, rounded cards (~8px), sticky minimal header (P. logo + burger menu), vertical social links (Vm. / Ig. / Fb.) on the right edge, "Chat with us" pill at the bottom.
- **Animations:** client-logo marquee, testimonial slider, project-page image sliders + parallax, fade-in on scroll, accordions, count-up numbers, video lightbox.
- **Today's weak spots:** heavy pages (110–170 KB HTML plus many scripts, 118 MB of images), no Arabic version of the main pages, burger-only menu on desktop, WooCommerce and old pages still indexed.

## Open questions for the owner
1. **Videos (7 MP4s on project pages):** host them in the new site, or embed them from Vimeo?
2. **Arabic copy:** I'll draft the Arabic text from the English (and reuse the existing Arabic page). OK, or will your team supply the translations?
3. **Blog and jobs:** bring all 8 posts over as static pages (proposed), and point jobs to SocialFlow careers?
4. **Riyadh contact email:** the contact page lists a personal staff address for Riyadh. Keep it, or use info@?
