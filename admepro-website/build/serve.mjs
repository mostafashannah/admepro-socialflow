#!/usr/bin/env node
// Tiny local preview server for ./dist (no dependencies).
//   node build/serve.mjs            → http://localhost:4321
//   PORT=8080 node build/serve.mjs
// Behaves like the real host: clean URLs, the 301 redirect map (dist/_redirects) and the 404 pages.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DIST = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', process.env.OUT_DIR || 'dist');
const PORT = +process.env.PORT || 4321;
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json', '.xml': 'application/xml', '.txt': 'text/plain', '.webp': 'image/webp', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.mp4': 'video/mp4' };
const redirects = new Map();
try {
  for (const line of fs.readFileSync(path.join(DIST, '_redirects'), 'utf8').split('\n')) {
    const [from, to] = line.trim().split(/\s+/);
    if (from && to && !from.includes('*')) redirects.set(from, to);
  }
} catch { /* no redirects file */ }

http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  let p = decodeURIComponent(url.pathname);
  const r = redirects.get(p) || redirects.get(p.endsWith('/') ? p : p + '/');
  if (r) { res.writeHead(301, { Location: r }); return res.end(); }
  let file = path.join(DIST, p);
  if (!file.startsWith(DIST)) { res.writeHead(403); return res.end(); }
  if (fs.existsSync(file) && fs.statSync(file).isDirectory()) {
    if (!p.endsWith('/')) { res.writeHead(301, { Location: p + '/' + url.search }); return res.end(); }
    file = path.join(file, 'index.html');
  }
  if (!fs.existsSync(file)) {
    const nf = path.join(DIST, p.startsWith('/ar/') ? 'ar/404.html' : '404.html');
    res.writeHead(404, { 'Content-Type': TYPES['.html'] });
    return fs.createReadStream(nf).pipe(res);
  }
  const stat = fs.statSync(file);
  const type = TYPES[path.extname(file)] || 'application/octet-stream';
  // byte ranges so <video> works in Safari
  const range = req.headers.range && /bytes=(\d*)-(\d*)/.exec(req.headers.range);
  if (range) {
    const start = +range[1] || 0, end = range[2] ? +range[2] : stat.size - 1;
    res.writeHead(206, { 'Content-Type': type, 'Content-Range': `bytes ${start}-${end}/${stat.size}`, 'Accept-Ranges': 'bytes', 'Content-Length': end - start + 1 });
    return fs.createReadStream(file, { start, end }).pipe(res);
  }
  res.writeHead(200, { 'Content-Type': type, 'Content-Length': stat.size, 'Accept-Ranges': 'bytes' });
  fs.createReadStream(file).pipe(res);
}).listen(PORT, () => console.log(`Preview: http://localhost:${PORT}  (Arabic: http://localhost:${PORT}/ar/)`));
