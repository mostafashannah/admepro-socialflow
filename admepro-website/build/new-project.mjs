#!/usr/bin/env node
// Create a new portfolio project folder from the template.
//   node build/new-project.mjs <slug> "<Title>" [--from /path/to/images]
// Example:
//   node build/new-project.mjs al-mousa "Al Mousa" --from ~/Downloads/al-mousa-photos
// Then fill in src/content/projects/<slug>/project.json and run: npm run build
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const [slug, title, ...rest] = process.argv.slice(2);
if (!slug || !title || !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(slug)) {
  console.error('Usage: node build/new-project.mjs <slug-lowercase-with-dashes> "<Title>" [--from <images folder>]');
  process.exit(1);
}
const dir = path.join(ROOT, 'src/content/projects', slug);
if (fs.existsSync(dir)) { console.error(`✖ ${path.relative(ROOT, dir)} already exists`); process.exit(1); }

const tpl = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/content/projects/_template/project.json'), 'utf8'));
delete tpl._help;
tpl.title = title;
tpl.client = { en: title, ar: title };
const orders = fs.readdirSync(path.join(ROOT, 'src/content/projects')).filter((d) => !d.startsWith('_'))
  .map((d) => { try { return JSON.parse(fs.readFileSync(path.join(ROOT, 'src/content/projects', d, 'project.json'), 'utf8')).order || 0; } catch { return 0; } });
tpl.order = Math.max(0, ...orders) + 1;
fs.mkdirSync(path.join(dir, 'images'), { recursive: true });

const fromIdx = rest.indexOf('--from');
if (fromIdx >= 0 && rest[fromIdx + 1]) {
  // convert + resize every image to WebP (max 2000px) with tools/optimize_images.py (needs Python + Pillow)
  execFileSync('python3', ['-I', path.join(ROOT, 'tools/optimize_images.py'), rest[fromIdx + 1], path.join(dir, 'images')], { stdio: 'inherit' });
  const imgs = fs.readdirSync(path.join(dir, 'images')).filter((f) => f.endsWith('.webp')).sort();
  if (imgs.length) {
    tpl.cover = `images/${imgs[0]}`;
    tpl.sections[0].media = imgs.map((f) => ({ type: 'image', src: `images/${f}`, alt: `${title}` }));
  }
}
fs.writeFileSync(path.join(dir, 'project.json'), JSON.stringify(tpl, null, 2) + '\n');
console.log(`✔ created ${path.relative(ROOT, dir)}/project.json (order ${tpl.order})
Next: put images in ${path.relative(ROOT, dir)}/images/, fill in the English + Arabic text, then run: npm run build`);
