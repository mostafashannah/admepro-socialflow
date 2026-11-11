#!/usr/bin/env python3
"""Convert a folder of images (jpg/png/webp/heic-free) to web-ready WebP.

    python3 tools/optimize_images.py <input folder> <output folder> [--max 2000] [--quality 80]

- resizes so the longest side is at most --max pixels (default 2000)
- fixes phone rotation (EXIF), keeps transparency
- names files lowercase-with-dashes, e.g. "IMG 4690.JPG" -> "img-4690.webp"
Needs Pillow:  pip install pillow
"""
import argparse, os, re, sys
from PIL import Image, ImageOps

ap = argparse.ArgumentParser()
ap.add_argument('src'); ap.add_argument('dst')
ap.add_argument('--max', type=int, default=2000); ap.add_argument('--quality', type=int, default=80)
a = ap.parse_args()
os.makedirs(a.dst, exist_ok=True)
n = 0
for name in sorted(os.listdir(a.src)):
    base, ext = os.path.splitext(name)
    if ext.lower() not in ('.jpg', '.jpeg', '.png', '.webp', '.tif', '.tiff'):
        continue
    clean = re.sub(r'[^a-z0-9]+', '-', base.lower()).strip('-') or f'image-{n + 1}'
    im = ImageOps.exif_transpose(Image.open(os.path.join(a.src, name)))
    im = im.convert('RGBA' if ('A' in im.getbands() or im.mode == 'P') else 'RGB')
    im.thumbnail((a.max, a.max))
    out = os.path.join(a.dst, clean + '.webp')
    im.save(out, 'WEBP', quality=a.quality, method=6)
    n += 1
    print(f'  {name} -> {os.path.relpath(out)} {im.size[0]}x{im.size[1]} {os.path.getsize(out) // 1024} KB')
print(f'done: {n} image(s)')
sys.exit(0 if n else 1)
