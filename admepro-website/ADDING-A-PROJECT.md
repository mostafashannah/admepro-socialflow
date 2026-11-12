# Adding a new project (for future Claude sessions)

Each project is **one folder**, `src/content/projects/<slug>/`, containing:
- `project.json`: all the text, in English and Arabic
- `images/`: WebP images

Nothing else needs editing. The portfolio page, filters, home page grid, sitemap, structured data, Arabic version and `api/projects.json` all update automatically.

## The one-step process

```bash
cd admepro-website
npm run new-project -- <slug> "<Title>" --from <folder with the client's photos>
```

This creates the folder. It also converts every photo to WebP (max 2000 px) with a clean file name and pre-fills a gallery. Then:

1. Open `src/content/projects/<slug>/project.json` and fill in the fields (table below). Write the Arabic as well; every `{ "en": …, "ar": … }` needs both.
2. Run `npm run build`. It stops with a clear message if anything is wrong: a missing image, an unknown tag or category, or broken JSON.
3. Check the page with `npm run serve`, at `/project/<slug>/` and `/ar/project/<slug>/`.
4. Commit only inside `admepro-website/`, on the `admepro-website` branch.

## project.json fields

| Field | Required | Notes |
|---|---|---|
| `title` | yes | Project name as shown (same in both languages) |
| `client` | yes | `{ "en": "", "ar": "" }` |
| `order` | yes | Position in the portfolio (1 = first) |
| `published` | yes | `false` hides the project everywhere |
| `featured` | no | `true` = shown in the home page "Work" grid (first 10 by order) |
| `categories` | yes | Any of `branding`, `creative`, `digital`, `production` (these drive the filters) |
| `tags` | no | Keys from `src/content/tags.json`. To add a new tag, add it there first, with its Arabic name |
| `date` | recommended | `YYYY-MM-DD`, shown on the banner ("December 15, 2023") |
| `color` | recommended | Dark background colour of the green-style intro block, e.g. `#2f3830` |
| `intro.bg` / `intro.ghost` | no | Own look for the intro block: any CSS background (e.g. a `linear-gradient(...)`) and the colour of the faint name down the edge. Cote uses a pink-to-blue gradient |
| `displayTitle` | no | Spelling used in the intro block (e.g. "Côte") when it differs from `title` |
| `cover` | yes | `images/cover.webp`: used on cards, in shares and as the hero |
| `hero` | no | A different hero image, if the cover is a square crop |
| `heroVideo` | no | Full-screen banner video: `{ "src": "/assets/video/x.mp4", "poster": "/assets/video/x.webp" }` (used by Kohi Bar, Skycorp, Autogate) |
| `seo.title` / `seo.description` | recommended | 50–60 / 120–155 characters, EN + AR |
| `summary` | yes | 2–3 sentences, EN + AR |
| `meta.strategy` / `meta.design` | no | Short lists, EN + AR |
| `sections[]` | no | Each one: optional `label`, `heading`, `text` (`{ "en": [paragraphs], "ar": [paragraphs] }`), `media[]` and `layout` |
| `stats[]` | no | `{ "value": 2500, "prefix": "+", "suffix": "K", "label": {…} }` |
| `credits[]` | no | `{ "role": {…}, "people": [{ "name": "…", "title": {…} }] }` |

If the first section has no `label`, it is shown as the big coloured intro block (heading on the right, paragraphs on the left, the project name faintly down the edge).

### Media types (inside `sections[].media`)
```json
{ "type": "image", "src": "images/photo-1.webp", "alt": "What is in the photo" }
{ "type": "vimeo", "id": "1027080421", "poster": "images/film.webp", "alt": "Campaign film" }
{ "type": "vimeo", "id": "1029338303", "poster": "images/reel.webp", "ratio": "9/16" }
{ "type": "video", "src": "/assets/video/clip.mp4", "poster": "/assets/video/clip.webp" }
{ "type": "beforeAfter", "before": "images/before.webp", "after": "images/after.webp" }
```
Layouts: `wide` (one big item), `grid-2`, `grid-3`, `grid-4`. A section can also have `swatches` (colour chips) or `palette` (image + name + text), as in the Zen and Cote projects.

### Good examples to copy
- **Branding project:** `cote/`, `zen/`, `kohi-bar/`
- **Video production:** `asus-zenfone/`, `verdi/` (Vimeo + credits)
- **Social media / performance:** `goldsgym/`, `autogate/` (reels + stats)
- **Photo gallery only:** `tablya/`, `pescado/`, `mcm/`

## Rules
- Folder name: lowercase-with-dashes, and it becomes the URL. Never rename a published project's folder; if you must, add a redirect in `src/content/redirects.json`.
- Images: WebP, 2000 px max (the script does this). Self-hosted MP4s go in `src/assets/video/`. Compress them first, for example: `ffmpeg -i in.mp4 -vf "scale='min(1280,iw)':-2" -c:v libx264 -crf 28 -movflags +faststart -c:a aac -b:a 96k out.mp4`. Longer films go on Vimeo.
- Only write numbers and facts the owner has confirmed.
- Never touch SocialFlow files or the live WordPress site.
