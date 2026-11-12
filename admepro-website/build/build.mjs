#!/usr/bin/env node
// admepro.com static site generator. Zero dependencies: Node 18+ only.
//
//   node build/build.mjs                 → builds ./dist for production (https://admepro.com)
//   SITE_ENV=staging SITE_URL=https://new.admepro.com node build/build.mjs
//                                        → test build: noindex everywhere + "test site" ribbon
//   LEAD_ENDPOINT=https://… node build/build.mjs
//                                        → forms POST leads there (default: stub mode, nothing is sent)
//
// Content lives in src/content (JSON). Templates are the functions in this file.
// Every page is generated in English (/) and Arabic (/ar/).

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC = path.join(ROOT, 'src');
const CONTENT = path.join(SRC, 'content');
const DIST = path.join(ROOT, process.env.OUT_DIR || 'dist');
const LANGS = ['en', 'ar'];

// ---------------------------------------------------------------- load content
const readJSON = (p) => {
  try { return JSON.parse(fs.readFileSync(p, 'utf8')); }
  catch (e) { throw new Error(`Invalid JSON in ${path.relative(ROOT, p)}: ${e.message}`); }
};
const site = readJSON(path.join(CONTENT, 'site.json'));
const shared = readJSON(path.join(CONTENT, 'shared.json'));
const tags = readJSON(path.join(CONTENT, 'tags.json'));
const P = {
  home: readJSON(path.join(CONTENT, 'pages/home.json')),
  about: readJSON(path.join(CONTENT, 'pages/about.json')),
  contact: readJSON(path.join(CONTENT, 'pages/contact.json')),
  services: readJSON(path.join(CONTENT, 'pages/services.json')),
  gcc: readJSON(path.join(CONTENT, 'pages/gcc.json')),
  listing: readJSON(path.join(CONTENT, 'pages/listing.json')),
};
const redirects = readJSON(path.join(CONTENT, 'redirects.json'));

const ENV = process.env.SITE_ENV === 'staging' ? 'staging' : 'production';
const BASE = (process.env.SITE_URL || site.url).replace(/\/$/, '');
const LEAD_ENDPOINT = process.env.LEAD_ENDPOINT || site.forms?.endpoint || '';
const BUILD_TIME = new Date().toISOString();

const errors = [];
const warn = (m) => errors.push(m);

// projects: one folder per project, sorted by "order"
const projectsDir = path.join(CONTENT, 'projects');
const projects = fs.readdirSync(projectsDir, { withFileTypes: true })
  .filter((d) => d.isDirectory() && !d.name.startsWith('_'))
  .map((d) => {
    const file = path.join(projectsDir, d.name, 'project.json');
    if (!fs.existsSync(file)) { warn(`projects/${d.name}: missing project.json`); return null; }
    const p = readJSON(file);
    p.slug = d.name;
    p.dir = path.join(projectsDir, d.name);
    return p;
  })
  .filter((p) => p && p.published !== false)
  .sort((a, b) => (a.order ?? 999) - (b.order ?? 999) || a.title.localeCompare(b.title));
const projectBySlug = Object.fromEntries(projects.map((p) => [p.slug, p]));

const posts = fs.readdirSync(path.join(CONTENT, 'posts'))
  .filter((f) => f.endsWith('.json'))
  .map((f) => readJSON(path.join(CONTENT, 'posts', f)))
  .filter((p) => p.published !== false)
  .sort((a, b) => (b.date || '').localeCompare(a.date || ''));

// ---------------------------------------------------------------- validation
const CATS = P.listing.portfolio.categories.map((c) => c.key);
for (const p of projects) {
  const where = `projects/${p.slug}/project.json`;
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(p.slug)) warn(`${where}: folder name must be lowercase-with-dashes`);
  for (const k of ['title', 'cover', 'summary', 'categories']) if (!p[k]) warn(`${where}: missing "${k}"`);
  for (const c of p.categories || []) if (!CATS.includes(c)) warn(`${where}: unknown category "${c}" (use: ${CATS.join(', ')})`);
  for (const t of p.tags || []) if (!tags[t]) warn(`${where}: unknown tag "${t}". Add it to src/content/tags.json`);
  const files = [p.cover, p.hero, ...(p.sections || []).flatMap((s) => [
    ...(s.media || []).flatMap((m) => [m.src, m.poster, m.before, m.after]),
    ...(s.palette || []).map((x) => x.image),
  ])].filter((f) => f && !f.startsWith('/'));
  for (const f of files) if (!fs.existsSync(path.join(p.dir, f))) warn(`${where}: file not found: ${f}`);
}

// ---------------------------------------------------------------- helpers
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const tx = (v, lang) => (v == null ? '' : typeof v === 'string' || typeof v === 'number' || Array.isArray(v) ? v : v[lang] ?? v.en ?? '');
const t = (key, lang) => {
  const v = key.split('.').reduce((o, k) => o?.[k], site.t);
  if (v == null) { warn(`missing UI string: ${key}`); return key; }
  return tx(v, lang);
};
const nl2br = (s) => esc(s).replace(/\n/g, '<br>');
// localise an internal path. Blog posts exist in English only, so they are never prefixed.
const L = (p, lang) => (lang !== 'ar' || /^\/blog\/.+/.test(p) || /^https?:/.test(p) ? p : p === '/' ? '/ar/' : '/ar' + p);
const abs = (p) => BASE + p;
const minutes = (html) => Math.max(1, Math.round(html.replace(/<[^>]+>/g, ' ').split(/\s+/).length / 220));
const fmtDate = (d, lang) => d ? new Date(d + 'T00:00:00Z').toLocaleDateString(lang === 'ar' ? 'ar-EG' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }) : '';

// read WebP/PNG/JPEG dimensions without dependencies (prevents layout shift)
const dimCache = new Map();
function imageSize(file) {
  if (dimCache.has(file)) return dimCache.get(file);
  let r = null;
  try {
    const b = fs.readFileSync(file);
    if (b.toString('ascii', 0, 4) === 'RIFF' && b.toString('ascii', 8, 12) === 'WEBP') {
      const c = b.toString('ascii', 12, 16);
      if (c === 'VP8X') r = [1 + b.readUIntLE(24, 3), 1 + b.readUIntLE(27, 3)];
      else if (c === 'VP8L') { const n = b.readUInt32LE(21); r = [1 + (n & 0x3fff), 1 + ((n >> 14) & 0x3fff)]; }
      else if (c === 'VP8 ') r = [b.readUInt16LE(26) & 0x3fff, b.readUInt16LE(28) & 0x3fff];
    } else if (b.readUInt32BE(0) === 0x89504e47) r = [b.readUInt32BE(16), b.readUInt32BE(20)];
    else if (b[0] === 0xff && b[1] === 0xd8) {
      let i = 2;
      while (i < b.length) { const m = b[i + 1], len = b.readUInt16BE(i + 2); if (m >= 0xc0 && m <= 0xc3) { r = [b.readUInt16BE(i + 7), b.readUInt16BE(i + 5)]; break; } i += 2 + len; }
    }
  } catch { /* missing file is reported by the link checker */ }
  dimCache.set(file, r);
  return r;
}
const publicToFile = (url) => {
  const m = url.match(/^\/project\/([^/]+)\/(.+)$/);
  if (m) return path.join(projectsDir, m[1], m[2]);
  return path.join(SRC, url);
};
const projImg = (p, rel) => (rel?.startsWith('/') ? rel : `/project/${p.slug}/${rel}`);
const siteImg = (key) => `/assets/img/site/${key}.webp`;

function img(src, alt, { cls = '', eager = false, sizes = '100vw', attrs = '' } = {}) {
  const d = imageSize(publicToFile(src));
  const wh = d ? ` width="${d[0]}" height="${d[1]}"` : '';
  return `<img src="${esc(src)}" alt="${esc(alt)}"${wh}${cls ? ` class="${cls}"` : ''} ${eager ? 'fetchpriority="high"' : 'loading="lazy"'} decoding="async" sizes="${sizes}"${attrs ? ' ' + attrs : ''}>`;
}

const icon = {
  arrow: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  plus: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  play: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>',
  up: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  close: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  prev: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  wa: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2c-1.5 0-3-.4-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"/></svg>',
};

// ---------------------------------------------------------------- assets (hashed)
const assetMap = {};
function emitAsset(rel, outName) {
  const buf = fs.readFileSync(path.join(SRC, rel));
  const hash = crypto.createHash('sha1').update(buf).digest('hex').slice(0, 8);
  const ext = path.extname(outName);
  const name = outName.replace(ext, `.${hash}${ext}`);
  const out = path.join(DIST, 'assets', name);
  fs.mkdirSync(path.dirname(out), { recursive: true });
  fs.writeFileSync(out, buf);
  assetMap[rel] = `/assets/${name}`;
  return assetMap[rel];
}

// ---------------------------------------------------------------- page shell
function head({ lang, title, description, pathEn, pathAr, image, type = 'website', schema = [], noAlt = false }) {
  const canonical = abs(lang === 'ar' ? pathAr : pathEn);
  const ogImg = abs(image || '/assets/img/brand/og-default.jpg');
  const alts = noAlt ? '' : `
<link rel="alternate" hreflang="en" href="${abs(pathEn)}">
<link rel="alternate" hreflang="ar" href="${abs(pathAr)}">
<link rel="alternate" hreflang="x-default" href="${abs(pathEn)}">`;
  const robots = ENV === 'staging' ? '<meta name="robots" content="noindex, nofollow">' : '<meta name="robots" content="index, follow, max-image-preview:large">';
  const fonts = lang === 'ar'
    ? ['ibm-plex-sans-arabic-arabic-400-normal.woff2', 'ibm-plex-sans-arabic-arabic-600-normal.woff2']
    : ['dm-sans-latin-400-normal.woff2', 'dm-sans-latin-600-normal.woff2', 'montserrat-latin-700-normal.woff2'];
  return `<!doctype html>
<html lang="${lang}" dir="${lang === 'ar' ? 'rtl' : 'ltr'}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>${esc(title)}</title>
<meta name="description" content="${esc(description)}">
${robots}
<link rel="canonical" href="${canonical}">${alts}
${fonts.map((f) => `<link rel="preload" href="/assets/fonts/${f}" as="font" type="font/woff2" crossorigin>`).join('\n')}
<link rel="stylesheet" href="${assetMap['assets/css/site.css']}">
<link rel="icon" href="/assets/img/brand/favicon-32.png" sizes="32x32">
<link rel="icon" href="/assets/img/brand/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/assets/img/brand/apple-touch-icon.png">
<meta name="theme-color" content="#d90a2c">
<meta property="og:site_name" content="admepro">
<meta property="og:type" content="${type}">
<meta property="og:title" content="${esc(title)}">
<meta property="og:description" content="${esc(description)}">
<meta property="og:url" content="${canonical}">
<meta property="og:image" content="${ogImg}">
<meta property="og:locale" content="${lang === 'ar' ? 'ar_EG' : 'en_US'}">
<meta property="og:locale:alternate" content="${lang === 'ar' ? 'en_US' : 'ar_EG'}">
<meta name="twitter:card" content="summary_large_image">
${schema.filter(Boolean).map((s) => `<script type="application/ld+json">${JSON.stringify({ '@context': 'https://schema.org', ...s }).replace(/</g, '\\u003c')}</script>`).join('\n')}
<script>(function(d){d.classList.add('js');var t;try{t=localStorage.getItem('theme')}catch(e){}if(!t)t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';d.dataset.theme=t})(document.documentElement)</script>
<meta name="color-scheme" content="light dark">
</head>`;
}

// Header, full-screen menu, right rail and footer copy the SocialFlow careers page
// (socialflow.admepro.com/careers → CareersChrome / CareersFullMenu / CareersFooter in app.jsx),
// so the website and the careers page look like one site.
const brandIco = {
  vimeo: 'M22.396 7.164c-.093 2.026-1.507 4.799-4.245 8.317-2.828 3.675-5.223 5.512-7.184 5.512-1.215 0-2.24-1.123-3.078-3.369-.559-2.052-1.118-4.104-1.677-6.156-.622-2.246-1.29-3.369-2.004-3.369-.156 0-.7.328-1.634.98l-.978-1.26c1.027-.902 2.04-1.804 3.037-2.708C6.03 4.04 6.977 3.505 7.6 3.447c1.484-.143 2.398.871 2.74 3.042.37 2.343.626 3.799.769 4.368.427 1.938.897 2.907 1.41 2.907.4 0 .999-.633 1.799-1.899.797-1.266 1.225-2.229 1.28-2.891.111-1.093-.316-1.64-1.28-1.64-.457 0-.925.104-1.409.313.936-3.066 2.724-4.556 5.365-4.469 1.958.06 2.883 1.324 2.771 3.986',
  instagram: 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41-.56-.22-.96-.48-1.38-.9-.42-.42-.68-.82-.9-1.38-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41 1.27-.06 1.65-.07 4.85-.07M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.38C1.35 2.68.94 3.35.63 4.14.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.38 2.13.67.66 1.34 1.07 2.13 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.31 1.46-.72 2.13-1.38.66-.67 1.07-1.34 1.38-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.31-.79-.72-1.46-1.38-2.13C21.32 1.35 20.65.94 19.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0z M12 5.84A6.16 6.16 0 1 0 12 18.16 6.16 6.16 0 0 0 12 5.84M12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8z M18.41 4.15a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88z',
  facebook: 'M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11.01 10.13 11.93v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.08 24 18.09 24 12.07z',
};
const logoImgs = (h) => `<img src="/assets/img/brand/mark-dark.webp" alt="admepro" width="${Math.round(h * 972 / 900)}" height="${h}" class="logo-on-light"><img src="/assets/img/brand/mark-light.webp" alt="" width="${Math.round(h * 972 / 900)}" height="${h}" class="logo-on-dark" aria-hidden="true">`;

function header(lang, current, otherHref) {
  const links = [
    ...site.nav.map((n) => ({ label: t(n.key, lang), href: L(n.path, lang), active: current === n.key || (n.children || []).includes(current) })),
    { label: t('career', lang), href: site.careersUrl },
  ];
  const face = (s) => `<span class="flip-face">${esc(s)}</span><span class="flip-face back" aria-hidden="true">${esc(s)}</span>`;
  return `<a class="skip" href="#main">${esc(t('skip', lang))}</a>
${ENV === 'staging' ? `<div class="ribbon" role="note">TEST SITE · ${lang === 'ar' ? 'نسخة تجريبية' : 'not live'}</div>` : ''}
<header class="chrome" data-header>
  <a class="chrome-logo" href="${L('/', lang)}" aria-label="admepro, ${esc(t('home', lang))}">${logoImgs(36)}</a>
  <button class="chrome-burger" type="button" aria-expanded="false" aria-controls="menu" data-burger><svg class="i no-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8h18M3 16h18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span class="sr">${esc(t('menu', lang))}</span></button>
</header>
<div class="fullmenu" id="menu" hidden data-menu>
  <button class="fullmenu-x" type="button" data-menu-close aria-label="${esc(t('close', lang))}">${icon.close}</button>
  <div class="fullmenu-grid">
    <div class="fm-spacer"></div>
    <nav class="fm-nav" aria-label="${lang === 'ar' ? 'القائمة الرئيسية' : 'Main'}">
      ${links.map((l) => `<a class="flip" href="${l.href}"${l.active ? ' aria-current="page"' : ''}><span class="flip-inner">${face(l.label)}</span></a>`).join('\n      ')}
    </nav>
    <div class="fm-spacer"></div>
    <div class="fm-info">
      <div><p class="fm-h">${lang === 'ar' ? 'تواصل معنا' : 'Get In Touch'}</p><p>${esc(tx(site.offices[0].address, lang))}</p><p>${site.infoEmail}</p><p dir="ltr">Ph: ${site.phone}</p></div>
      <div><p class="fm-h">${esc(t('workInquiries', lang))}</p><p>${site.email}</p><p dir="ltr">Ph: ${site.phone}</p></div>
      <div class="fm-social">${['vimeo', 'instagram', 'facebook'].map((k) => { const s = site.social.find((x) => x.key === k); return `<a href="${s.url}" rel="noopener" target="_blank" aria-label="${s.label}"><svg class="i no-flip" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="${brandIco[k]}"/></svg></a>`; }).join('')}</div>
      <a class="fm-lang" href="${otherHref}" hreflang="${lang === 'ar' ? 'en' : 'ar'}" lang="${lang === 'ar' ? 'en' : 'ar'}">${esc(t('langSwitch', lang))}</a>
    </div>
  </div>
</div>
<button class="search-btn" type="button" data-search-open aria-label="${esc(t('search', lang))}"><svg class="i no-flip" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 20l-3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
<div class="search" data-search hidden role="dialog" aria-modal="true" aria-label="${esc(t('search', lang))}">
  <button class="fullmenu-x" type="button" data-search-close aria-label="${esc(t('close', lang))}">${icon.close}</button>
  <div class="search-in">
    <input type="search" class="search-input" placeholder="${esc(t('searchPh', lang))}" aria-label="${esc(t('search', lang))}" autocomplete="off" data-index="${lang === 'ar' ? '/ar/search.json' : '/search.json'}" data-empty="${esc(t('searchEmpty', lang))}">
    <ul class="search-results" aria-live="polite"></ul>
  </div>
</div>
<aside class="rail" aria-label="${lang === 'ar' ? 'الإعدادات والروابط' : 'Settings and social links'}">
  <div class="rail-pill" role="group" aria-label="${lang === 'ar' ? 'المظهر' : 'Theme'}">
    <button type="button" data-theme-set="dark" title="${lang === 'ar' ? 'الوضع الداكن' : 'Dark mode'}" aria-label="${lang === 'ar' ? 'الوضع الداكن' : 'Dark mode'}"><svg class="i no-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
    <button type="button" data-theme-set="light" title="${lang === 'ar' ? 'الوضع الفاتح' : 'Light mode'}" aria-label="${lang === 'ar' ? 'الوضع الفاتح' : 'Light mode'}"><svg class="i no-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
  </div>
  <div class="rail-social">${site.social.filter((s) => s.short).sort((a, b) => (a.railOrder ?? 9) - (b.railOrder ?? 9)).map((s) => `<a href="${s.url}" rel="noopener" target="_blank" aria-label="${s.label}">${s.short}</a>`).join('<span>/</span>')}</div>
</aside>`;
}

function footer(lang) {
  const cairo = site.offices[0];
  const wa = `https://wa.me/${site.whatsapp}?text=${encodeURIComponent(tx(site.whatsappText, lang))}`;
  return `<section class="cta-band">
  <div class="wrap cta-band-in reveal">
    <div><h2 class="h2">${nl2br(t('footerCta', lang).replace(', ', ',\n').replace('، ', '،\n'))}</h2><p class="muted small">${esc(t('footerCtaSub', lang))}</p></div>
    <a class="btn btn-red" href="${L('/contact-us/', lang)}">${esc(t('getQuote', lang))}</a>
  </div>
</section>
<footer class="cfooter">
  <div class="cfooter-in">
    <div class="cfooter-top">
      <a class="cfooter-logo" href="${L('/', lang)}" aria-label="admepro">${logoImgs(32)}</a>
      <div class="cfooter-cols">
        <div class="cfooter-col">
          <div><p class="cf-h">${lang === 'ar' ? 'القاهرة' : 'Cairo'}</p><p class="cf-t">${lang === 'ar' ? '145 البنفسج 3<br>القاهرة الجديدة، القاهرة' : '145 El Banafsig 3<br>New Cairo, Cairo'}</p></div>
          <div><p class="cf-h">${esc(t('call', lang))}</p><p class="cf-t"><a href="tel:${site.phoneHref}" dir="ltr">+2 0100 037 0140</a></p></div>
          <div><p class="cf-h">${esc(t('gps', lang))}</p><p class="cf-t" dir="ltr"><a href="https://maps.google.com/?q=${cairo.geo.join(',')}" rel="noopener" target="_blank">${cairo.geo[0]}"<br>${cairo.geo[1]}"</a></p></div>
        </div>
        <div class="cfooter-col">
          <div><p class="cf-h">${esc(t('workInquiries', lang))}</p><p class="cf-t">${esc(t('workInquiriesText', lang))}</p><a class="cf-strong" href="mailto:${site.email}">${site.email}</a></div>
          <div><p class="cf-h">${esc(t('career', lang))}</p><p class="cf-t">${esc(t('careerText', lang))}</p><a class="cf-accent" href="${site.careersUrl}">${esc(t('seePositions', lang))}</a></div>
          <div class="cf-social">${['facebook', 'instagram', 'vimeo'].map((k) => { const s = site.social.find((x) => x.key === k); return `<a href="${s.url}" rel="noopener" target="_blank">${s.label}</a>`; }).join('')}<a href="${wa}" rel="noopener" target="_blank">WhatsApp</a></div>
        </div>
      </div>
    </div>
    <a href="#top" class="cf-top" data-top><span class="cf-top-ico"><svg class="i no-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>${esc(t('scrollTop', lang))}</a>
    <div class="cf-bottom"><p>© ${site.foundingYear}-${new Date().getFullYear()} admepro . ${esc(t('rights', lang))}</p></div>
  </div>
</footer>
<div class="chat-pill" data-chat hidden>
  <span>${esc(t('chatPrompt', lang))}</span>
  <a class="chat-btn" href="${wa}" rel="noopener" target="_blank">${esc(t('chatCta', lang))}</a>
  <button type="button" class="chat-x" aria-label="${esc(t('close', lang))}" data-chat-close>${icon.close}</button>
</div>
<script src="${assetMap['assets/js/site.js']}" defer></script>
</body>
</html>`;
}

function page({ lang, key, pathEn, pathAr, title, description, image, type, schema, body, noAlt, otherHref }) {
  const other = otherHref || (lang === 'ar' ? pathEn : pathAr);
  return `${head({ lang, title, description, pathEn, pathAr, image, type, schema, noAlt })}
<body class="page-${key}" id="top">
${header(lang, key, other)}
<main id="main">
${body}
</main>
${footer(lang)}`;
}

// ---------------------------------------------------------------- schema.org
const orgId = abs('/#organization');
function orgSchema() {
  return {
    '@type': ['Organization', 'ProfessionalService'],
    '@id': orgId,
    name: 'admepro',
    url: abs('/'),
    logo: abs('/assets/img/brand/wordmark.webp'),
    image: abs('/assets/img/brand/og-default.jpg'),
    email: site.email,
    telephone: site.phone,
    foundingDate: String(site.foundingYear),
    description: tx(P.home.seo.description, 'en'),
    sameAs: site.social.map((s) => s.url),
    areaServed: ['EG', 'SA', 'AE', 'BH', 'OM', 'KW', 'QA'],
    knowsLanguage: ['en', 'ar'],
    address: { '@type': 'PostalAddress', streetAddress: '145 El Banafsig 3', addressLocality: 'New Cairo', addressRegion: 'Cairo', addressCountry: 'EG' },
    location: site.offices.map((o) => ({
      '@type': 'Place',
      name: `admepro ${tx(o.city, 'en')}`,
      address: { '@type': 'PostalAddress', streetAddress: tx(o.address, 'en'), addressLocality: o.locality, addressRegion: o.region, addressCountry: o.country },
      geo: { '@type': 'GeoCoordinates', latitude: o.geo[0], longitude: o.geo[1] },
      telephone: o.phone,
    })),
  };
}
const websiteSchema = (lang) => ({ '@type': 'WebSite', '@id': abs('/#website'), url: abs(L('/', lang)), name: 'admepro', inLanguage: lang, publisher: { '@id': orgId } });
const crumbs = (lang, items) => ({
  '@type': 'BreadcrumbList',
  itemListElement: [{ name: t('home', lang), path: '/' }, ...items].map((c, i) => ({ '@type': 'ListItem', position: i + 1, name: c.name, item: abs(L(c.path, lang)) })),
});
const faqSchema = (lang) => ({
  '@type': 'FAQPage',
  mainEntity: shared.faq.map((f) => ({ '@type': 'Question', name: tx(f.q, lang), acceptedAnswer: { '@type': 'Answer', text: tx(f.a, lang).join(' ') } })),
});

// ---------------------------------------------------------------- shared blocks
const eyebrow = (s) => (s ? `<p class="eyebrow">${esc(s)}</p>` : '');

function clientMarquee(lang) {
  const items = shared.clientStrips.map((k) => img(siteImg('clients/' + k), '', { sizes: '320px' })).join('');
  return `<section class="clients" aria-label="${esc(tx(P.home.clientsTitle, lang))}">
  <div class="wrap row-between"><p class="eyebrow">${esc(tx(P.home.clientsTitle, lang))}</p><a class="link-sm" href="${L('/portfolio/', lang)}">${esc(t('seeMoreProjects', lang))}</a></div>
  <div class="marquee" aria-hidden="true"><div class="marquee-track">${items}${items}</div></div>
</section>`;
}

function clientGrid(lang, keys = shared.clientGrid) {
  return `<section class="logo-grid-wrap"><div class="wrap"><ul class="logo-grid">${keys.map((k) => `<li>${img(siteImg('clients/' + k + '-black'), shared.clientNames[k] || k, { sizes: '160px' })}</li>`).join('')}</ul></div></section>`;
}

function projectCard(p, lang, { wide = false, eager = false } = {}) {
  const cats = p.categories.map((c) => esc(tx(P.listing.portfolio.categories.find((x) => x.key === c)?.label, lang))).join(', ');
  return `<a class="pcard${wide ? ' wide' : ''}" href="${L(`/project/${p.slug}/`, lang)}" data-cats="${p.categories.join(' ')}" data-tags="${(p.tags || []).join(' ')}">
  ${img(projImg(p, p.cover), p.title, { eager, sizes: wide ? '(min-width: 900px) 66vw, 100vw' : '(min-width: 900px) 33vw, 100vw' })}
  <span class="pcard-meta"><span class="pcard-title">${esc(p.title)}</span><span class="pcard-cats">${cats}</span></span>
</a>`;
}

function serviceColumns(lang) {
  return `<div class="svc-cols">${shared.serviceColumns.map((c) => `<details class="svc-col"><summary><span class="plus">${icon.plus}</span><span class="h6">${esc(tx(c.title, lang))}</span></summary><ul>${tx(c.items, lang).map((i) => `<li>${esc(i)}</li>`).join('')}</ul></details>`).join('')}</div>`;
}

function testimonials(lang, dark = true) {
  const items = shared.testimonials;
  return `<div class="tslider${dark ? ' dark' : ''}" data-slider>
  <p class="tag">${lang === 'ar' ? 'آراء العملاء' : 'Testimonials'}</p>
  <div class="tslides">${items.map((x, i) => `<figure class="tslide"${i ? ' hidden' : ''}><blockquote>“${esc(tx(x.quote, lang))}”</blockquote><figcaption><strong>${esc(tx(x.name, lang))}</strong><span>${esc(tx(x.role, lang))}</span></figcaption></figure>`).join('')}</div>
  <div class="tnav">
    <div class="tbtns"><button type="button" data-prev aria-label="Previous">${icon.prev}</button><button type="button" data-next aria-label="Next">${icon.arrow}</button></div>
    <span class="tcount"><span data-current>1</span><i></i>${items.length}</span>
  </div>
</div>`;
}

function statsRow(stats, lang, cls = '') {
  return `<ul class="stats ${cls}">${stats.map((s) => `<li><span class="stat-num"><span data-count="${s.value}">${s.value}</span><sup>${esc(s.suffix || '')}</sup></span><span class="stat-label">${nl2br(tx(s.label, lang))}</span></li>`).join('')}</ul>`;
}

function faqBlock(lang) {
  return `<div class="faq card-light">
  ${eyebrow(tx(P.home.faqEyebrow, lang))}
  <h2 class="h3">${esc(tx(P.home.faqTitle, lang))}</h2>
  ${shared.faq.map((f, i) => `<details class="faq-item"${i === 0 ? ' open' : ''}><summary>${esc(tx(f.q, lang))}<span class="plus">${icon.plus}</span></summary><div>${tx(f.a, lang).map((a) => `<p>${esc(a)}</p>`).join('')}</div></details>`).join('')}
</div>`;
}

// lead forms: markup only. Behaviour + SocialFlow stub live in assets/js/site.js
function field(lang, name, { type = 'text', required = false, ph = '', auto = '', full = false } = {}) {
  const label = t(`form.${name}`, lang);
  return `<label class="field${full ? ' full' : ''}"><span>${esc(label)} <em>(${esc(t(required ? 'form.required' : 'form.optional', lang))})</em></span>
<input name="${name}" type="${type}"${required ? ' required' : ''}${ph ? ` placeholder="${esc(t(`form.${ph}`, lang))}"` : ''}${auto ? ` autocomplete="${auto}"` : ''}${type === 'tel' || type === 'email' ? ' dir="ltr"' : ''}></label>`;
}
function select(lang, name, listKey, required = false) {
  const opts = site.t.form[listKey];
  return `<label class="field"><span>${esc(t(`form.${name}`, lang))} <em>(${esc(t(required ? 'form.required' : 'form.optional', lang))})</em></span>
<select name="${name}"${required ? ' required' : ''}>${opts.map((o) => `<option value="${o.value}">${esc(o[lang])}</option>`).join('')}</select></label>`;
}
function leadForm(lang, kind) {
  const attrs = `class="lead-form ${kind}" data-lead-form="${kind}" data-endpoint="${esc(LEAD_ENDPOINT)}" data-lang="${lang}" novalidate`;
  const common = `<input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
<p class="form-status" role="status" aria-live="polite"></p>`;
  if (kind === 'call') {
    return `<form ${attrs}>
${field(lang, 'name', { required: true, ph: 'namePh', auto: 'name' })}
${field(lang, 'company', { required: true, ph: 'companyPh', auto: 'organization' })}
${field(lang, 'email', { type: 'email', required: true, ph: 'emailPh', auto: 'email' })}
${field(lang, 'phone', { type: 'tel', required: true, ph: 'phonePh', auto: 'tel' })}
${select(lang, 'bestTime', 'times')}
${common}
<button class="btn btn-light" type="submit">${esc(t('form.bookCall', lang))}</button>
</form>`;
  }
  return `<form ${attrs}>
<div class="form-grid">
${field(lang, 'name', { required: true, ph: 'namePh', auto: 'name' })}
${field(lang, 'company', { required: true, ph: 'companyPh', auto: 'organization' })}
${field(lang, 'email', { type: 'email', required: true, ph: 'emailPh', auto: 'email' })}
${field(lang, 'phone', { type: 'tel', ph: 'phonePh', auto: 'tel' })}
${select(lang, 'service', 'services')}
${select(lang, 'budget', 'budgets')}
<label class="field full"><span>${esc(t('form.details', lang))} <em>(${esc(t('form.optional', lang))})</em></span><textarea name="message" rows="4" placeholder="${esc(t('form.detailsPh', lang))}"></textarea></label>
<label class="field full"><span>${esc(t('form.brief', lang))} <em>(${esc(t('form.optional', lang))})</em></span><input type="file" name="brief" accept=".pdf,.doc,.docx,.ppt,.pptx,.key,.png,.jpg"></label>
</div>
${common}
<button class="btn btn-dark" type="submit">${esc(t('form.send', lang))}</button>
</form>`;
}

function media(p, m, lang) {
  const alt = m.alt || p.title;
  if (m.type === 'image') return `<figure class="m">${img(projImg(p, m.src), alt, { sizes: '(min-width: 900px) 50vw, 100vw' })}</figure>`;
  if (m.type === 'vimeo') {
    return `<figure class="m video${m.ratio === '9/16' ? ' tall' : ''}"><button type="button" class="vfacade" data-vimeo="${esc(m.id)}" aria-label="${esc(t('playVideo', lang))}: ${esc(alt)}">
${m.poster ? img(projImg(p, m.poster), alt, { sizes: '(min-width: 900px) 66vw, 100vw' }) : ''}<span class="vplay">${icon.play}</span></button></figure>`;
  }
  if (m.type === 'video') {
    return `<figure class="m"><video class="lazy-video" muted playsinline loop preload="none" ${m.poster ? `poster="${esc(projImg(p, m.poster))}"` : ''} data-src="${esc(projImg(p, m.src))}" aria-label="${esc(alt)}"></video></figure>`;
  }
  if (m.type === 'beforeAfter') {
    return `<figure class="m ba" data-ba style="--pos:50%">${img(projImg(p, m.after), `${alt}: ${t('after', lang)}`, { sizes: '(min-width: 900px) 66vw, 100vw' })}
<div class="ba-before">${img(projImg(p, m.before), `${alt}: ${t('before', lang)}`, { sizes: '(min-width: 900px) 66vw, 100vw' })}</div>
<span class="ba-tag ba-tag-b">${esc(t('before', lang))}</span><span class="ba-tag ba-tag-a">${esc(t('after', lang))}</span>
<input type="range" min="0" max="100" value="50" aria-label="${esc(t('before', lang))} / ${esc(t('after', lang))}"></figure>`;
  }
  warn(`projects/${p.slug}: unknown media type "${m.type}"`);
  return '';
}

// ---------------------------------------------------------------- pages
function homePage(lang) {
  const H = P.home;
  const featured = projects.filter((p) => p.featured).slice(0, 10);
  const wideIdx = new Set([4, 7]);
  const fp = projectBySlug[H.featured.project];
  const body = `
<section class="hero"${H.hero.video ? ` data-hero-file="${esc(H.hero.video)}"` : ` data-hero-video="${esc(H.hero.vimeo)}" data-start="${H.hero.start}" data-end="${H.hero.end}"`}>
  <div class="hero-media" aria-hidden="true">${img(H.hero.poster, '', { cls: 'hero-poster', eager: true, sizes: '100vw' })}</div>
  <div class="hero-shade" aria-hidden="true"></div>
  <div class="wrap hero-in">
    <p class="hero-eyebrow" dir="ltr">admepro<span>.</span></p>
    <h1 class="hero-title"><span class="sr">${esc(tx(H.hero.line1, lang))} ${esc(tx(H.hero.line2, lang))} ${esc(tx(H.hero.words, lang)[0])}</span><span aria-hidden="true">${esc(tx(H.hero.line1, lang))}<br>${esc(tx(H.hero.line2, lang))} <span class="typed" data-words="${esc(JSON.stringify(tx(H.hero.words, lang)))}">${esc(tx(H.hero.words, lang)[0])}</span><span class="caret">|</span></span></h1>
    <a class="hero-link" href="${L(H.hero.ctaHref, lang)}">${esc(tx(H.hero.cta, lang))}</a>
  </div>
</section>
${clientMarquee(lang)}
<section class="section">
  <div class="wrap split reveal">
    <div>${eyebrow(tx(H.intro.eyebrow, lang))}<h2 class="h3 lead-title">${esc(tx(H.intro.title, lang))}</h2></div>
    <div class="prose">${tx(H.intro.text, lang).map((p) => `<p>${esc(p)}</p>`).join('')}<a class="link-strong" href="${L('/contact-us/', lang)}">${esc(tx(H.intro.cta, lang))} ${icon.arrow}</a></div>
  </div>
  <div class="wrap reveal">${serviceColumns(lang)}</div>
</section>
<section class="section-sm">
  <div class="wrap-wide">
    <div class="row-between"><h2 class="eyebrow-lg">${esc(tx(H.workTitle, lang))}</h2><a class="link-sm" href="${L('/portfolio/', lang)}">${esc(t('seeMoreProjects', lang))}</a></div>
    <div class="work-grid">${featured.map((p, i) => projectCard(p, lang, { wide: wideIdx.has(i), eager: i < 3 })).join('')}</div>
  </div>
</section>
<section class="section">
  <div class="wrap">
    <div class="split reveal">
      <div>${eyebrow(tx(H.revenue.eyebrow, lang))}<h2 class="h2">${nl2br(tx(H.revenue.title, lang))}</h2></div>
      <p class="prose">${esc(tx(H.revenue.text, lang))}</p>
    </div>
    <div class="rev-cards">${H.revenue.cards.map((c) => `<a class="rev-card${c.dark ? ' dark' : ''} reveal" href="${L(c.href, lang)}">
      <span class="rev-top"><span class="tag-sm">${esc(tx(c.tag, lang))}</span><span class="h5">${esc(tx(c.title, lang))}</span></span>
      ${img(siteImg(c.image), '', { cls: 'rev-img', sizes: '(min-width: 900px) 25vw, 50vw' })}
      <span class="rev-text">${esc(tx(c.text, lang))}</span></a>`).join('')}</div>
  </div>
</section>
<section class="featured">
  ${img(projImg(fp, fp.cover), fp.title, { cls: 'featured-bg', sizes: '100vw' })}
  <div class="wrap featured-in reveal">
    <p class="featured-label">${esc(tx(H.featured.eyebrow, lang))}</p>
    <span class="tag-sm light">${fp.categories.map((c) => esc(tx(P.listing.portfolio.categories.find((x) => x.key === c).label, lang))).join(', ')}</span>
    <h2 class="h2">${esc(tx(H.featured.title, lang))}</h2>
    <p>${esc(tx(H.featured.text, lang))}</p>
    <a class="btn btn-dark" href="${L('/project/' + fp.slug + '/', lang)}">${esc(t('readMore', lang))}</a>
  </div>
</section>
<section class="section">
  <div class="wrap split proof">
    <div class="reveal">
      ${eyebrow(tx(H.proof.eyebrow, lang))}
      <h2 class="h3">${esc(tx(H.proof.title, lang))} <span class="muted">${esc(tx(H.proof.titleMuted, lang))}</span></h2>
      <div class="bar"><span>${esc(tx(H.proof.barLabel, lang))}</span><div class="bar-track"><i style="--v:100%"></i><b>100%</b></div></div>
      <p>${esc(tx(H.proof.text, lang))}</p>
      <a class="link-strong" href="${L('/portfolio/', lang)}">${esc(tx(H.proof.cta, lang))}</a>
    </div>
    <div class="reveal">${testimonials(lang)}</div>
  </div>
</section>
<section class="section-sm">
  <div class="wrap-wide"><div class="showcase reveal">
    <div class="showcase-top">
      <div class="showcase-video"><p>${esc(tx(H.showcase.label, lang))}</p>
        <button type="button" class="play-round" data-vimeo-modal="${H.showcase.vimeo}" aria-label="${esc(t('playVideo', lang))}">${icon.play}</button></div>
      <div class="showcase-text"><h2 class="h3">${esc(tx(H.showcase.title, lang))}</h2>
        <div class="btn-row"><a class="btn btn-ghost-dark" href="${L('/about/', lang)}">${esc(t('about', lang))}</a><a class="btn btn-black" href="${L('/portfolio/', lang)}">${esc(t('seeMoreProjects', lang))}</a></div></div>
    </div>
    ${statsRow(shared.stats, lang, 'on-dark')}
  </div></div>
</section>
<section class="team-cards">
  ${img(siteImg('home/team-cards'), '', { cls: 'team-bg', sizes: '100vw' })}
  <div class="wrap team-cards-in">${H.teamCards.map((c, i) => `<a class="tcard${i ? ' dark' : ''} reveal" href="${L(c.href, lang)}"><span class="tcard-title">${esc(tx(c.title, lang))} <span class="circle">${icon.arrow}</span></span>
    <span class="tcard-pre">${esc(tx(c.pre, lang))}</span><span class="tcard-val"><span data-count="${c.value}">${c.value}</span>${esc(tx(c.unit, lang))}</span><span class="tcard-post">${esc(tx(c.post, lang))}</span></a>`).join('')}</div>
</section>
<section class="section">
  <div class="wrap split contact-faq">
    <div class="call-card reveal">${eyebrow(tx(H.contact.eyebrow, lang))}<h2 class="h2">${nl2br(tx(H.contact.title, lang))}</h2>${leadForm(lang, 'call')}</div>
    <div class="reveal">${faqBlock(lang)}</div>
  </div>
</section>`;
  return page({
    lang, key: 'home', pathEn: '/', pathAr: '/ar/', title: tx(H.seo.title, lang), description: tx(H.seo.description, lang),
    schema: [orgSchema(), websiteSchema(lang), faqSchema(lang)], body,
  });
}

function aboutPage(lang) {
  const A = P.about;
  const body = `
<section class="about-hero"><div class="wrap"><h1 class="mega" dir="ltr">admepro<span class="red">.</span></h1></div></section>
<section class="section-sm"><div class="wrap reveal">${eyebrow(tx(A.eyebrow, lang))}<p class="h2 about-lead">${esc(tx(A.title, lang))}</p></div></section>
<section class="section tint">
  <div class="wrap split-3">
    <div class="reveal">${eyebrow(tx(A.servicesEyebrow, lang))}<h2 class="h3">${esc(tx(A.servicesTitle, lang))}</h2></div>
    <div class="about-svcs">${A.services.map((s, i) => `<div class="about-svc reveal"><span class="num">0${i + 1}.</span><h3 class="h6">${nl2br(tx(s.title, lang))}</h3>${tx(s.text, lang).map((p) => `<p>${esc(p)}</p>`).join('')}</div>`).join('')}</div>
  </div>
</section>
<section class="section">
  <div class="wrap split-3">
    <div class="reveal">${eyebrow(tx(A.teamEyebrow, lang))}<h2 class="h3">${esc(tx(A.teamTitle, lang))}</h2></div>
    <ul class="team">${A.team.map((m) => `<li class="reveal"><strong>${esc(tx(m.name, lang))}</strong><span>${esc(tx(m.role, lang))}</span></li>`).join('')}</ul>
  </div>
</section>
<section class="section">
  <div class="wrap split-3">
    <div class="reveal">${eyebrow(tx(A.numbersEyebrow, lang))}<h2 class="h3">${esc(tx(A.numbersTitle, lang))}</h2></div>
    <div class="reveal">${statsRow(A.numbers, lang, 'compact')}
      <div class="bar gradient"><span>${esc(tx(A.conversion.label, lang))}</span><div class="bar-track"><i style="--v:${A.conversion.value}%"></i><b>${A.conversion.value}%</b></div></div></div>
  </div>
</section>
<section class="studios">
  <div class="studios-img">${img(siteImg(A.studioImage), '', { sizes: '(min-width: 900px) 50vw, 100vw' })}</div>
  <div class="studios-text reveal"><h2 class="h3">${esc(tx(A.studiosTitle, lang))}</h2>
    ${A.studios.map((s, i) => `<details class="faq-item plain"${i === 0 ? ' open' : ''}><summary>${esc(tx(s.title, lang))}<span class="plus">${icon.plus}</span></summary><div><p>${esc(tx(s.text, lang))}</p></div></details>`).join('')}</div>
</section>
${clientGrid(lang)}`;
  return page({
    lang, key: 'about', pathEn: '/about/', pathAr: '/ar/about/', title: tx(A.seo.title, lang), description: tx(A.seo.description, lang),
    schema: [orgSchema(), crumbs(lang, [{ name: t('about', lang), path: '/about/' }])], body,
  });
}

function contactPage(lang) {
  const C = P.contact;
  const body = `
<section class="page-head"><div class="wrap">${eyebrow(tx(C.eyebrow, lang))}<h1 class="h1">${esc(tx(C.title, lang))}</h1></div></section>
<section class="section-sm"><div class="wrap offices">${site.offices.map((o) => `
  <article class="office reveal">
    <h2 class="h5">${esc(tx(o.city, lang))}</h2>
    <p class="coords" dir="ltr">${o.geo.join(', ')}</p>
    <dl>
      <dt>${esc(tx(C.labels.location, lang))}</dt><dd>${esc(tx(o.address, lang))}</dd>
      <dt>${esc(tx(C.labels.inquiries, lang))}</dt><dd><a href="mailto:${o.email}">${o.email}</a></dd>
      <dt>${esc(tx(C.labels.hours, lang))}</dt><dd>${esc(tx(o.hours, lang))}</dd>
      <dt>${esc(tx(C.labels.phone, lang))}</dt><dd><a href="tel:${o.phoneHref}" dir="ltr">${o.phone}</a></dd>
    </dl>
    <a class="link-strong" href="https://www.google.com/maps/search/?api=1&amp;query=${o.geo.join(',')}" rel="noopener" target="_blank">${esc(tx(C.labels.map, lang))} ${icon.arrow}</a>
  </article>`).join('')}</div></section>
<section class="section"><div class="wrap split-3">
  <div class="reveal"><h2 class="h4">${esc(tx(C.formTitle, lang))}</h2><p class="muted">${esc(tx(C.formText, lang))} <a href="mailto:${site.email}">${site.email}</a></p></div>
  <div class="reveal">${leadForm(lang, 'quote')}</div>
</div></section>
<section class="dark-band"><div class="wrap cta-band-in">
  <div>${eyebrow(tx(C.careers.eyebrow, lang))}<h2 class="h3">${esc(tx(C.careers.title, lang))}</h2></div>
  <a class="btn btn-red" href="${site.careersUrl}">${esc(tx(C.careers.cta, lang))}</a>
</div></section>`;
  return page({
    lang, key: 'contact', pathEn: '/contact-us/', pathAr: '/ar/contact-us/', title: tx(C.seo.title, lang), description: tx(C.seo.description, lang),
    schema: [orgSchema(), crumbs(lang, [{ name: t('contact', lang), path: '/contact-us/' }])], body,
  });
}

function servicePage(S, lang) {
  const c = P.services.common;
  const samples = S.projects.map((s) => projectBySlug[s]).filter(Boolean);
  const body = `
<section class="svc-hero">
  <div class="wrap svc-hero-in">
    <div class="reveal">${eyebrow(tx(S.eyebrow, lang))}<h1 class="h1">${esc(tx(S.title, lang))}</h1><p class="lead">${esc(tx(S.lead, lang))}</p></div>
  </div>
</section>
<section class="section-sm">
  <div class="wrap svc-body">
    <div class="svc-list">${S.items.map((it, i) => `<details class="svc-item reveal"${i === 0 ? ' open' : ''}><summary><span class="plus">${icon.plus}</span><span class="h6">${esc(tx(it.title, lang))}</span></summary><p>${esc(tx(it.text, lang))}</p></details>`).join('')}</div>
    <div class="svc-img reveal">${img(siteImg(c.image), '', { sizes: '(min-width: 900px) 45vw, 100vw' })}</div>
  </div>
</section>
<section class="section-sm">
  <div class="wrap">
    <div class="row-between"><h2 class="eyebrow-lg">${esc(tx(c.workSamples, lang))}</h2><a class="link-sm" href="${L('/portfolio/', lang)}?cat=${S.key}">${esc(tx(c.moreProjects, lang))}</a></div>
    <ul class="sample-list">${samples.map((p) => `<li><a href="${L(`/project/${p.slug}/`, lang)}" class="sample">${img(projImg(p, p.cover), p.title, { sizes: '(min-width: 900px) 20vw, 50vw' })}<span>${esc(p.title)}</span></a></li>`).join('')}</ul>
  </div>
</section>
<section class="section-sm">
  <div class="wrap svc-testi">
    <div class="svc-testi-img">${img(siteImg(c.image2), '', { sizes: '(min-width: 900px) 60vw, 100vw' })}</div>
    <div class="svc-testi-card reveal">${testimonials(lang)}</div>
  </div>
</section>
<section class="section">
  <div class="wrap split reveal">
    ${statsRow(c.stats, lang, 'red-nums')}
    <div><h2 class="h4">${esc(tx(c.statsTitle, lang))}</h2><p class="muted small">${esc(tx(c.statsSub, lang))}</p></div>
  </div>
  <div class="wrap"><div class="svc-cols">${shared.serviceCtas.map((s) => `<details class="svc-col"><summary><span class="plus">${icon.plus}</span><span class="h6">${esc(tx(s.title, lang))}</span></summary><ul>${tx(s.items, lang).map((i) => `<li>${esc(i)}</li>`).join('')}</ul><a class="link-sm" href="${L(s.href, lang)}">${esc(t('readMore', lang))} ${icon.arrow}</a></details>`).join('')}</div></div>
</section>
${clientGrid(lang)}`;
  return page({
    lang, key: S.key, pathEn: S.path, pathAr: '/ar' + S.path, title: tx(S.seo.title, lang), description: tx(S.seo.description, lang),
    schema: [
      { '@type': 'Service', name: tx(S.title, lang), serviceType: tx(S.title, 'en'), provider: { '@id': orgId }, areaServed: ['EG', 'SA', 'AE'], description: tx(S.seo.description, lang),
        hasOfferCatalog: { '@type': 'OfferCatalog', name: tx(S.title, lang), itemListElement: S.items.map((it) => ({ '@type': 'Offer', itemOffered: { '@type': 'Service', name: tx(it.title, lang) } })) } },
      crumbs(lang, [{ name: t('services', lang), path: '/branding/' }, { name: tx(S.title, lang), path: S.path }]),
    ],
    body,
  });
}

function gccPage(lang) {
  const G = P.gcc;
  const H = P.home;
  const list = G.projects.map((s) => projectBySlug[s]).filter(Boolean);
  const body = `
<section class="page-head gcc-head"><div class="wrap">
  <dl class="gcc-meta"><div><dt>${esc(tx(G.labelLocation, lang))}</dt><dd>${esc(tx(G.metaLocation, lang))}</dd></div><div><dt>${esc(tx(G.labelServices, lang))}</dt><dd>${esc(tx(G.metaServices, lang))}</dd></div></dl>
  ${eyebrow(tx(G.eyebrow, lang))}<h1 class="h1">${esc(tx(G.title, lang))}</h1>
</div></section>
<section class="section-sm"><div class="wrap-wide"><div class="work-grid">${list.map((p, i) => projectCard(p, lang, { wide: i === 0, eager: i < 2 })).join('')}</div></div></section>
<section class="section">
  <div class="wrap split reveal">
    <div>${eyebrow(tx(H.intro.eyebrow, lang))}<h2 class="h3 lead-title">${esc(tx(H.intro.title, lang))}</h2></div>
    <div class="prose">${tx(H.intro.text, lang).map((p) => `<p>${esc(p)}</p>`).join('')}<a class="link-strong" href="${L('/contact-us/', lang)}">${esc(tx(H.intro.cta, lang))} ${icon.arrow}</a></div>
  </div>
  <div class="wrap reveal">${serviceColumns(lang)}</div>
</section>
<section class="section tint">
  <div class="wrap">
    <div class="split reveal"><h2 class="h2">${esc(tx(G.foundation.title, lang))}</h2><p class="prose">${esc(tx(G.foundation.text, lang))}</p></div>
    <div class="rev-cards">${G.foundation.cards.map((c) => `<div class="rev-card reveal">${img(siteImg(c.image), '', { cls: 'rev-img', sizes: '(min-width: 900px) 25vw, 50vw' })}<span class="h5">${esc(tx(c.title, lang))}</span><span class="rev-text">${esc(tx(c.text, lang))}</span></div>`).join('')}</div>
  </div>
</section>
<section class="section">
  <div class="wrap">
    <h2 class="h2 reveal narrow">${esc(tx(G.scale.title, lang))}</h2>
    <div class="split">${G.scale.items.map((i) => `<div class="reveal"><h3 class="h6">${esc(tx(i.title, lang))}</h3><p>${esc(tx(i.text, lang))}</p></div>`).join('')}</div>
    <div class="pillars">${G.scale.pillars.map((i, n) => `<div class="pillar reveal"><span class="num">0${n + 1}</span><h3 class="h5">${esc(tx(i.title, lang))}</h3><p>${esc(tx(i.text, lang))}</p></div>`).join('')}</div>
  </div>
</section>
<section class="section-sm"><div class="wrap-wide"><div class="showcase reveal">
  <div class="showcase-top"><div class="showcase-video"><p>${esc(tx(H.showcase.label, lang))}</p><button type="button" class="play-round" data-vimeo-modal="${H.showcase.vimeo}" aria-label="${esc(t('playVideo', lang))}">${icon.play}</button></div>
  <div class="showcase-text"><h2 class="h3">${esc(tx(H.showcase.title, lang))}</h2><div class="btn-row"><a class="btn btn-ghost-dark" href="${L('/about/', lang)}">${esc(t('about', lang))}</a><a class="btn btn-black" href="${L('/contact-us/', lang)}">${esc(t('getQuote', lang))}</a></div></div></div>
  ${statsRow(shared.stats, lang, 'on-dark')}
</div></div></section>
<section class="section"><div class="wrap split contact-faq">
  <div class="call-card reveal">${eyebrow(tx(H.contact.eyebrow, lang))}<h2 class="h2">${nl2br(tx(H.contact.title, lang))}</h2>${leadForm(lang, 'call')}</div>
  <div class="reveal">${faqBlock(lang)}</div>
</div></section>
<section class="logo-grid-wrap"><div class="wrap"><ul class="logo-grid five">${['gcc-1', 'gcc-2', 'gcc-3', 'gcc-4', 'gcc-5'].map((k) => `<li>${img(siteImg('clients/' + k), '', { sizes: '160px' })}</li>`).join('')}</ul></div></section>`;
  return page({
    lang, key: 'gcc', pathEn: G.path, pathAr: '/ar' + G.path, title: tx(G.seo.title, lang), description: tx(G.seo.description, lang),
    schema: [orgSchema(), faqSchema(lang), crumbs(lang, [{ name: t('gcc', lang), path: G.path }])], body,
  });
}

function portfolioPage(lang) {
  const PF = P.listing.portfolio;
  const count = (k) => projects.filter((p) => p.categories.includes(k)).length;
  const body = `
<section class="page-head"><div class="wrap">${eyebrow(tx(PF.eyebrow, lang))}<h1 class="h1">${esc(tx(PF.title, lang))}</h1>
  <div class="filters" role="group" aria-label="${esc(t('filterBy', lang))}" data-filters>
    <span class="muted small">${esc(t('filterBy', lang))}</span>
    <button type="button" class="chip" aria-pressed="true" data-filter="all">${esc(t('all', lang))} <sup>${String(projects.length).padStart(2, '0')}</sup></button>
    ${PF.categories.map((c) => `<button type="button" class="chip" aria-pressed="false" data-filter="${c.key}">${esc(tx(c.label, lang))} <sup>${String(count(c.key)).padStart(2, '0')}</sup></button>`).join('')}
  </div>
  <p class="filter-note small muted" data-filter-note hidden></p>
</div></section>
<section class="section-sm"><div class="wrap">
  <ul class="plist" data-plist>${projects.map((p) => `<li data-cats="${p.categories.join(' ')}" data-tags="${(p.tags || []).join(' ')}"><a href="${L(`/project/${p.slug}/`, lang)}" class="plist-item">
    <span class="plist-thumb">${img(projImg(p, p.cover), p.title, { sizes: '(min-width: 900px) 320px, 100vw' })}</span>
    <span class="plist-title">${esc(p.title)}</span><span class="plist-cats">/ ${p.categories.map((c) => esc(tx(PF.categories.find((x) => x.key === c).label, lang))).join(', ')}</span></a></li>`).join('')}</ul>
</div></section>
<section class="dark-band"><div class="wrap cta-band-in"><div>${eyebrow(tx(PF.ctaEyebrow, lang))}<h2 class="h3">${esc(tx(PF.ctaTitle, lang))}</h2></div><a class="btn btn-red" href="${L('/contact-us/', lang)}">${esc(t('getQuote', lang))}</a></div></section>`;
  return page({
    lang, key: 'portfolio', pathEn: '/portfolio/', pathAr: '/ar/portfolio/', title: tx(PF.seo.title, lang), description: tx(PF.seo.description, lang),
    schema: [
      { '@type': 'CollectionPage', name: tx(PF.title, lang), url: abs(L('/portfolio/', lang)), inLanguage: lang,
        mainEntity: { '@type': 'ItemList', itemListElement: projects.map((p, i) => ({ '@type': 'ListItem', position: i + 1, url: abs(L(`/project/${p.slug}/`, lang)), name: p.title })) } },
      crumbs(lang, [{ name: t('portfolio', lang), path: '/portfolio/' }]),
    ],
    body,
  });
}

function projectPage(p, lang) {
  const PF = P.listing.portfolio;
  const PR = P.listing.project;
  const i = projects.indexOf(p);
  const next = projects[(i + 1) % projects.length];
  const catLabels = p.categories.map((c) => tx(PF.categories.find((x) => x.key === c).label, lang));
  const meta = [
    p.meta?.strategy && [t('strategy', lang), tx(p.meta.strategy, lang)],
    p.meta?.design && [t('design', lang), tx(p.meta.design, lang)],
    [t('client', lang), tx(p.client, lang) || p.title],
  ].filter(Boolean);
  const heroMedia = p.heroVideo
    ? `<video class="phero-bg" autoplay muted loop playsinline preload="metadata" poster="${esc(p.heroVideo.poster)}"><source src="${esc(p.heroVideo.src)}" type="video/mp4"></video>`
    : img(projImg(p, p.hero || p.cover), p.title, { cls: 'phero-bg', eager: true, sizes: '100vw' });
  const sections = (p.sections || []).map((s) => `
<section class="psec reveal">
  <div class="wrap">
    ${s.label || s.heading || s.text ? `<div class="psec-head${s.text ? ' split' : ''}">
      <div>${s.label ? `<p class="eyebrow">${esc(tx(s.label, lang))}</p>` : ''}${s.heading ? `<h2 class="h3 caps">${esc(tx(s.heading, lang))}</h2>` : ''}</div>
      ${s.text ? `<div class="prose">${tx(s.text, lang).map((x) => `<p>${esc(x)}</p>`).join('')}</div>` : ''}
    </div>` : ''}
    ${s.swatches ? `<ul class="swatches">${s.swatches.map((w) => `<li><span class="sw" style="background:${esc(w.color)}"></span><strong>${esc(w.name)}</strong><span>${esc(w.code)}</span></li>`).join('')}</ul>` : ''}
    ${s.palette ? `<div class="palette">${s.palette.map((x) => `<figure>${img(projImg(p, x.image), tx(x.name, lang), { sizes: '(min-width: 900px) 33vw, 100vw' })}<figcaption><strong class="h5">${esc(tx(x.name, lang))}</strong><span>${esc(tx(x.text, lang))}</span></figcaption></figure>`).join('')}</div>` : ''}
    ${s.media?.length ? `<div class="pmedia ${s.layout || 'grid-2'}">${s.media.map((m) => media(p, m, lang)).join('')}</div>` : ''}
  </div>
</section>`).join('');
  const stats = p.stats?.length ? `<section class="section-sm"><div class="wrap split reveal"><h2 class="h3">${esc(t('byNumbers', lang))}</h2><ul class="stats red-nums">${p.stats.map((s) => `<li><span class="stat-num"><sup>${esc(s.prefix || '')}</sup><span data-count="${s.value}">${s.value}</span><sup>${esc(s.suffix || '')}</sup></span><span class="stat-label">${esc(tx(s.label, lang))}</span></li>`).join('')}</ul></div></section>` : '';
  const credits = p.credits?.length ? `<section class="credits"><div class="wrap credits-in">
    <h2 class="h4">${esc(t('peopleBehind', lang))}</h2>
    <div class="credit-cols">${p.credits.map((c) => `<div><p class="eyebrow">${esc(tx(c.role, lang))}</p><ul>${c.people.map((x) => `<li><strong>${esc(x.name)}</strong><span>${esc(tx(x.title, lang))}</span></li>`).join('')}</ul></div>`).join('')}</div>
  </div></section>` : '';
  const body = `
<section class="phero">
  ${heroMedia}
  <div class="wrap phero-in">
    <p class="phero-cats">${esc(catLabels.join(', '))}</p>
    <h1 class="h1">${esc(p.title)}</h1>
  </div>
</section>
<section class="section-sm"><div class="wrap pintro reveal">
  <p class="pintro-text">${esc(tx(p.summary, lang))}</p>
  <dl class="pmeta">${meta.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}
    ${p.tags?.length ? `<div><dt>${esc(t('tags', lang))}</dt><dd class="ptags">${p.tags.map((k) => `<a href="${L('/portfolio/', lang)}?tag=${k}">${esc(tx(tags[k], lang))}</a>`).join('')}</dd></div>` : ''}
  </dl>
</div></section>
${sections}
${stats}
${credits}
<section class="next-sec" style="--nc:${esc(p.color || '#1f1e22')}">
  <div class="wrap">
    <a class="next-card" href="${L(`/project/${next.slug}/`, lang)}" aria-label="${esc(t('nextProject', lang))}: ${esc(next.title)}">
      <span class="next-img">${img(projImg(next, next.cover), '', { sizes: '(min-width: 900px) 544px, 100vw' })}</span>
      <span class="next-body"><span class="next-label">${esc(t('nextProject', lang))}</span><span class="next-title">${esc(next.title)}</span></span>
    </a>
  </div>
</section>
<section class="section-sm tint"><div class="wrap cta-band-in">
  <div>${eyebrow(tx(PR.ctaEyebrow, lang))}<h2 class="h3">${nl2br(tx(PR.ctaTitle, lang))}</h2><p class="muted small">${esc(tx(PR.ctaText, lang))}</p></div>
  <a class="btn btn-dark" href="${L('/contact-us/', lang)}">${esc(t('getQuote', lang))} ${icon.arrow}</a>
</div></section>`;
  const url = `/project/${p.slug}/`;
  return page({
    lang, key: 'portfolio', pathEn: url, pathAr: '/ar' + url,
    title: tx(p.seo?.title, lang) || `${p.title} | admepro`,
    description: tx(p.seo?.description, lang) || tx(p.summary, lang).slice(0, 155),
    image: projImg(p, p.cover), type: 'article',
    schema: [
      { '@type': 'CreativeWork', name: p.title, url: abs(L(url, lang)), image: abs(projImg(p, p.cover)), inLanguage: lang,
        about: tx(p.summary, lang), creator: { '@id': orgId }, keywords: (p.tags || []).map((k) => tx(tags[k], 'en')).join(', '),
        ...(p.client ? { sourceOrganization: { '@type': 'Organization', name: tx(p.client, 'en') } } : {}) },
      crumbs(lang, [{ name: t('portfolio', lang), path: '/portfolio/' }, { name: p.title, path: url }]),
    ],
    body,
  });
}

function blogIndex(lang) {
  const B = P.listing.blog;
  const note = tx(B.arNote, lang);
  const body = `
<section class="page-head"><div class="wrap">${eyebrow(tx(B.eyebrow, lang))}<h1 class="h1">${esc(tx(B.title, lang))}</h1>${note ? `<p class="muted">${esc(note)}</p>` : ''}</div></section>
<section class="section-sm"><div class="wrap"><div class="post-grid">${posts.map((p, i) => `
  <a class="post-card reveal" href="/blog/${p.slug}/"${lang === 'ar' ? ' hreflang="en"' : ''}>
    ${img(p.cover, p.title, { eager: i < 3, sizes: '(min-width: 900px) 33vw, 100vw' })}
    <span class="post-meta">${esc(fmtDate(p.date, lang))} · ${minutes(p.bodyHtml)} ${esc(t('minRead', lang))}${lang === 'ar' ? ` · <em lang="en">${esc(t('englishOnly', lang))}</em>` : ''}</span>
    <span class="h5" lang="en" dir="ltr">${esc(p.title)}</span>
    <span class="post-ex" lang="en" dir="ltr">${esc(p.description)}</span>
  </a>`).join('')}</div></div></section>`;
  return page({
    lang, key: 'blog', pathEn: '/blog/', pathAr: '/ar/blog/', title: tx(B.seo.title, lang), description: tx(B.seo.description, lang),
    schema: [{ '@type': 'Blog', name: 'admepro Insights', url: abs(L('/blog/', lang)), publisher: { '@id': orgId },
      blogPost: posts.map((p) => ({ '@type': 'BlogPosting', headline: p.title, url: abs(`/blog/${p.slug}/`), datePublished: p.date })) }, crumbs(lang, [{ name: t('blog', lang), path: '/blog/' }])],
    body,
  });
}

function postPage(p) {
  const lang = 'en';
  const related = posts.filter((x) => x.slug !== p.slug).slice(0, 3);
  const url = `/blog/${p.slug}/`;
  const body = `
<article>
  <header class="page-head post-head"><div class="wrap narrow">
    <a class="link-sm" href="/blog/">← ${esc(t('blog', lang))}</a>
    <h1 class="h2">${esc(p.title)}</h1>
    <p class="post-meta">${esc(fmtDate(p.date, lang))} · ${minutes(p.bodyHtml)} ${esc(t('minRead', lang))}</p>
  </div></header>
  <div class="wrap narrow post-cover">${img(p.cover, p.title, { eager: true, sizes: '(min-width: 900px) 760px, 100vw' })}</div>
  <div class="wrap narrow post-body prose">${p.bodyHtml}</div>
</article>
<section class="section-sm tint"><div class="wrap"><h2 class="h4">${esc(t('relatedPosts', lang))}</h2><div class="post-grid">${related.map((r) => `
  <a class="post-card" href="/blog/${r.slug}/">${img(r.cover, r.title, { sizes: '(min-width: 900px) 33vw, 100vw' })}<span class="post-meta">${esc(fmtDate(r.date, lang))}</span><span class="h5">${esc(r.title)}</span></a>`).join('')}</div></div></section>`;
  return page({
    lang, key: 'blog', pathEn: url, pathAr: '/ar/blog/', otherHref: '/ar/blog/', noAlt: true,
    title: `${p.title} | admepro`, description: p.description, image: p.cover, type: 'article',
    schema: [{ '@type': 'BlogPosting', headline: p.title, description: p.description, datePublished: p.date, dateModified: p.date, image: abs(p.cover),
      url: abs(url), mainEntityOfPage: abs(url), inLanguage: 'en', author: { '@id': orgId }, publisher: { '@id': orgId } },
      crumbs(lang, [{ name: t('blog', lang), path: '/blog/' }, { name: p.title, path: url }])],
    body,
  });
}

function notFound(lang) {
  const body = `<section class="nf"><div class="wrap"><p class="mega">404<span class="red">.</span></p><h1 class="h3">${esc(t('notFoundTitle', lang))}</h1><p class="muted">${esc(t('notFoundText', lang))}</p>
  <p class="btn-row"><a class="btn btn-dark" href="${L('/', lang)}">${esc(t('backHome', lang))}</a><a class="btn btn-ghost" href="${L('/portfolio/', lang)}">${esc(t('portfolio', lang))}</a></p></div></section>`;
  return page({ lang, key: '404', pathEn: '/404.html', pathAr: '/ar/404.html', title: `${t('notFoundTitle', lang)} | admepro`, description: t('notFoundText', lang), body, noAlt: true, otherHref: L('/', lang === 'ar' ? 'en' : 'ar') })
    .replace('<meta name="robots" content="index, follow, max-image-preview:large">', '<meta name="robots" content="noindex">');
}

// ---------------------------------------------------------------- write
const written = [];
function write(urlPath, html) {
  const file = urlPath.endsWith('.html') ? path.join(DIST, urlPath) : path.join(DIST, urlPath, 'index.html');
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, html.replace(/\n\s*\n/g, '\n'));
  written.push(urlPath);
}
function copyDir(from, to, filter = () => true) {
  if (!fs.existsSync(from)) return;
  for (const e of fs.readdirSync(from, { withFileTypes: true })) {
    const a = path.join(from, e.name), b = path.join(to, e.name);
    if (e.isDirectory()) copyDir(a, b, filter);
    else if (filter(e.name)) { fs.mkdirSync(to, { recursive: true }); fs.copyFileSync(a, b); }
  }
}

fs.rmSync(DIST, { recursive: true, force: true });
fs.mkdirSync(DIST, { recursive: true });
emitAsset('assets/css/site.css', 'css/site.css');
emitAsset('assets/js/site.js', 'js/site.js');
copyDir(path.join(SRC, 'assets/fonts'), path.join(DIST, 'assets/fonts'));
copyDir(path.join(SRC, 'assets/img'), path.join(DIST, 'assets/img'));
copyDir(path.join(SRC, 'assets/video'), path.join(DIST, 'assets/video'));
for (const p of projects) copyDir(p.dir, path.join(DIST, 'project', p.slug), (n) => n !== 'project.json' && !n.startsWith('.'));

const sitemap = [];
const addUrl = (en, ar, opts = {}) => sitemap.push({ en, ar, ...opts });
for (const lang of LANGS) {
  write(L('/', lang), homePage(lang));
  write(L('/about/', lang), aboutPage(lang));
  write(L('/contact-us/', lang), contactPage(lang));
  write(L('/portfolio/', lang), portfolioPage(lang));
  write(L(P.gcc.path, lang), gccPage(lang));
  write(L('/blog/', lang), blogIndex(lang));
  for (const S of P.services.pages) write(L(S.path, lang), servicePage(S, lang));
  for (const p of projects) write(L(`/project/${p.slug}/`, lang), projectPage(p, lang));
  write(lang === 'ar' ? '/ar/404.html' : '/404.html', notFound(lang));
}
for (const p of posts) write(`/blog/${p.slug}/`, postPage(p));

addUrl('/', '/ar/', { pri: '1.0' });
for (const u of ['/about/', '/portfolio/', '/contact-us/', P.gcc.path, '/blog/', ...P.services.pages.map((s) => s.path)]) addUrl(u, '/ar' + u, { pri: '0.8' });
for (const p of projects) addUrl(`/project/${p.slug}/`, `/ar/project/${p.slug}/`, { pri: '0.7', img: projImg(p, p.cover) });
for (const p of posts) addUrl(`/blog/${p.slug}/`, null, { pri: '0.6', lastmod: p.date });

// sitemap.xml (with hreflang pairs)
const today = BUILD_TIME.slice(0, 10);
const urlEntry = (loc, u) => `<url><loc>${abs(loc)}</loc><lastmod>${u.lastmod || today}</lastmod><priority>${u.pri}</priority>${u.ar ? `
<xhtml:link rel="alternate" hreflang="en" href="${abs(u.en)}"/><xhtml:link rel="alternate" hreflang="ar" href="${abs(u.ar)}"/><xhtml:link rel="alternate" hreflang="x-default" href="${abs(u.en)}"/>` : ''}${u.img ? `<image:image><image:loc>${abs(u.img)}</image:loc></image:image>` : ''}</url>`;
fs.writeFileSync(path.join(DIST, 'sitemap.xml'), `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
${sitemap.flatMap((u) => [urlEntry(u.en, u), u.ar ? urlEntry(u.ar, u) : null]).filter(Boolean).join('\n')}
</urlset>
`);

fs.writeFileSync(path.join(DIST, 'robots.txt'), ENV === 'staging'
  ? 'User-agent: *\nDisallow: /\n'
  : `User-agent: *\nAllow: /\n\nSitemap: ${abs('/sitemap.xml')}\n`);


// client-side search index (used by the search button)
for (const lang of LANGS) {
  const items = [
    { k: 'page', t: t('home', lang), u: L('/', lang), d: tx(P.home.seo.description, lang) },
    { k: 'page', t: t('about', lang), u: L('/about/', lang), d: tx(P.about.seo.description, lang) },
    { k: 'page', t: t('portfolio', lang), u: L('/portfolio/', lang), d: tx(P.listing.portfolio.seo.description, lang) },
    { k: 'page', t: t('contact', lang), u: L('/contact-us/', lang), d: tx(P.contact.seo.description, lang) },
    { k: 'page', t: t('gcc', lang), u: L(P.gcc.path, lang), d: tx(P.gcc.seo.description, lang) },
    { k: 'page', t: t('blog', lang), u: L('/blog/', lang), d: tx(P.listing.blog.seo.description, lang) },
    ...P.services.pages.map((S) => ({ k: 'page', t: tx(S.title, lang), u: L(S.path, lang), d: tx(S.seo.description, lang), x: S.items.map((i) => tx(i.title, lang)).join(' ') })),
    ...projects.map((p) => ({ k: 'project', t: p.title, u: L(`/project/${p.slug}/`, lang), d: tx(p.summary, lang).slice(0, 140), i: projImg(p, p.cover),
      x: [tx(p.client, lang), tx(p.client, 'en'), ...(p.tags || []).flatMap((k) => [tx(tags[k], lang), tags[k].en]), ...p.categories].join(' ') })),
    ...posts.map((p) => ({ k: 'post', t: p.title, u: `/blog/${p.slug}/`, d: p.description, i: p.cover })),
  ];
  const labels = { project: t('kindProject', lang), page: t('kindPage', lang), post: t('kindPost', lang) };
  fs.writeFileSync(path.join(DIST, lang === 'ar' ? 'ar/search.json' : 'search.json'), JSON.stringify({ labels, items }));
}

// public read-only feed of projects (integration point for SocialFlow, see INTEGRATION.md)
fs.mkdirSync(path.join(DIST, 'api'), { recursive: true });
fs.writeFileSync(path.join(DIST, 'api/projects.json'), JSON.stringify({
  generatedAt: BUILD_TIME,
  projects: projects.map((p) => ({
    slug: p.slug, title: p.title, client: p.client, categories: p.categories, tags: p.tags, featured: !!p.featured, order: p.order,
    url: { en: abs(`/project/${p.slug}/`), ar: abs(`/ar/project/${p.slug}/`) }, cover: abs(projImg(p, p.cover)), summary: p.summary,
  })),
}, null, 2));

// redirect map: old WordPress URLs → new URLs (Apache, nginx and Netlify/Cloudflare formats)
const rules = [...redirects.rules];
for (const c of CATS) rules.push([`/portfolio-category/${c}/`, `/portfolio/?cat=${c}`]);
for (const k of Object.keys(tags).filter((k) => !k.startsWith('_'))) rules.push([`/portfolio-tag/${k}/`, `/portfolio/?tag=${k}`]);
for (const [from, to] of Object.entries(redirects.tagAliases || {})) rules.push([`/portfolio-tag/${from}/`, `/portfolio/?tag=${to}`]);
const reEsc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
fs.writeFileSync(path.join(DIST, '.htaccess'), `# Generated by build/build.mjs. Do not edit here; edit src/content/redirects.json
Options -Indexes
DirectoryIndex index.html
ErrorDocument 404 /404.html
<IfModule mod_rewrite.c>
RewriteEngine On
${ENV === 'production' ? `RewriteCond %{HTTPS} off [OR]
RewriteCond %{HTTP_HOST} ^www\\. [NC]
RewriteRule ^ https://${new URL(BASE).host}%{REQUEST_URI} [L,R=301]` : '# (staging: no https/www redirect)'}
${rules.map(([from, to]) => `RewriteRule ^${reEsc(from.replace(/^\//, '').replace(/\/$/, ''))}${from.endsWith('/') ? '/?' : ''}$ ${to} [R=301,L,NE]`).join('\n')}
RewriteRule ^(page/\\d+)/?$ / [R=301,L]
RewriteRule ^(feed|comments/feed)/?$ /blog/ [R=301,L]
RewriteRule ^wp-(admin|login\\.php|content|includes|json) / [R=301,L]
</IfModule>
<IfModule mod_headers.c>
<FilesMatch "\\.(css|js|woff2|webp|png|jpg|mp4|svg)$">
Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>
<FilesMatch "\\.(html|xml|txt|json)$">
Header set Cache-Control "public, max-age=300"
</FilesMatch>
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
${ENV === 'staging' ? 'Header always set X-Robots-Tag "noindex, nofollow"' : ''}
</IfModule>
<IfModule mod_deflate.c>
AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml application/xml text/plain
</IfModule>
AddType image/webp .webp
AddType font/woff2 .woff2
`);
fs.writeFileSync(path.join(DIST, '_redirects'), rules.map(([f, to]) => `${f} ${to} 301`).join('\n') + '\n/page/* / 301\n');
fs.writeFileSync(path.join(ROOT, 'deploy', 'nginx-redirects.conf'), `# Generated by build/build.mjs from src/content/redirects.json. Include inside the server { } block.
${rules.map(([from, to]) => `location = ${from} { return 301 ${to}; }${from.endsWith('/') ? `\nlocation = ${from.slice(0, -1)} { return 301 ${to}; }` : ''}`).join('\n')}
location ~ ^/page/\\d+/?$ { return 301 /; }
location ~ ^/wp-(admin|login\\.php|content|includes|json) { return 301 /; }
`);

// ---------------------------------------------------------------- link check
const exists = (u) => {
  const clean = decodeURI(u.split('#')[0].split('?')[0]);
  if (!clean || clean === '/') return fs.existsSync(path.join(DIST, 'index.html'));
  const f = path.join(DIST, clean);
  return fs.existsSync(f) && fs.statSync(f).isFile() || fs.existsSync(path.join(f, 'index.html'));
};
let checked = 0;
const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => e.isDirectory() ? walk(path.join(d, e.name)) : e.name.endsWith('.html') ? [path.join(d, e.name)] : []);
for (const f of walk(DIST)) {
  const html = fs.readFileSync(f, 'utf8');
  for (const m of html.matchAll(/(?:href|src|poster|data-src)="(\/[^"]*)"/g)) {
    checked++;
    if (!exists(m[1])) warn(`broken link in ${path.relative(DIST, f)} → ${m[1]}`);
  }
}

// ---------------------------------------------------------------- report
const size = (d) => fs.readdirSync(d, { withFileTypes: true }).reduce((s, e) => s + (e.isDirectory() ? size(path.join(d, e.name)) : fs.statSync(path.join(d, e.name)).size), 0);
console.log(`✔ ${written.length} pages (${projects.length} projects × 2 languages, ${posts.length} posts) → ${path.relative(process.cwd(), DIST) || '.'}`);
console.log(`  env=${ENV}  base=${BASE}  forms=${LEAD_ENDPOINT ? 'POST ' + LEAD_ENDPOINT : 'stub (nothing is sent)'}`);
console.log(`  ${checked} internal links checked · ${(size(DIST) / 1e6).toFixed(1)} MB total`);
if (errors.length) {
  console.error(`\n✖ ${errors.length} problem(s):\n  - ` + [...new Set(errors)].join('\n  - '));
  process.exit(1);
}
