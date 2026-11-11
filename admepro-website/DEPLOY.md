# Preview and test subdomain

The main site stays on WordPress. These steps only add a **separate test address** (the example below uses `new.admepro.com`; any name works). Nothing here changes admepro.com.

## A. Preview on your own computer (no server needed)
```bash
git fetch origin admepro-website && git checkout admepro-website
cd admepro-website
npm run preview          # needs Node 18+; nothing to install
```
Open http://localhost:4321 for English and http://localhost:4321/ar/ for Arabic. Stop it with Ctrl+C.

## B. Test subdomain

### 1. DNS (at your domain provider, or in Cloudflare if you use it)
Add **one** record:

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `new` | your server's IP (the same server SocialFlow runs on is fine) | Auto / 300 |

If you use Cloudflare, it can be proxied (orange cloud). Leave the existing `@` and `www` records untouched; they keep pointing at WordPress.

### 2. Build the test version
```bash
cd admepro-website
npm run build:staging     # = SITE_ENV=staging SITE_URL=https://new.admepro.com node build/build.mjs
```
A staging build:
- tells Google not to index it (meta tag, `robots.txt` and an `X-Robots-Tag` header)
- shows a small "TEST SITE" badge
- keeps forms in test mode

### 3. Upload
Copy the **contents** of `admepro-website/dist/` to the server, for example:
```bash
rsync -av --delete dist/ user@SERVER:/var/www/new.admepro.com/
```
Include the hidden `.htaccess` file. `rsync` with a trailing `/` copies it.

### 4a. Apache vhost (if the server uses Apache, like most cPanel hosts)
`/etc/apache2/sites-available/new.admepro.com.conf`:
```apache
<VirtualHost *:80>
    ServerName new.admepro.com
    DocumentRoot /var/www/new.admepro.com
    <Directory /var/www/new.admepro.com>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
```bash
sudo a2enmod rewrite headers deflate
sudo a2ensite new.admepro.com && sudo systemctl reload apache2
sudo certbot --apache -d new.admepro.com      # free HTTPS
```
The generated `.htaccess` handles clean URLs, all the old-URL 301 redirects, caching, security headers and the 404 page.

On **cPanel**, go to Domains → Create subdomain `new`, with document root `public_html/new`. Upload the contents of `dist/` there, then run AutoSSL.

### 4b. nginx (if the server uses nginx)
Use `deploy/nginx-new.admepro.com.conf`, which includes the generated `deploy/nginx-redirects.conf`:
```bash
sudo cp deploy/nginx-new.admepro.com.conf /etc/nginx/sites-available/new.admepro.com
sudo cp deploy/nginx-redirects.conf /etc/nginx/snippets/admepro-redirects.conf
sudo ln -s /etc/nginx/sites-available/new.admepro.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d new.admepro.com
```

### 5. Check
- `https://new.admepro.com/` and `/ar/` load.
- `https://new.admepro.com/homeold/` redirects to `/`.
- `https://new.admepro.com/portfolio-category/branding/` opens the portfolio filtered to Branding.
- Submitting the contact form shows "Test mode: nothing was sent."

## C. Going live later (not now)
Only when you decide to launch:
1. Run `npm run build`. This is the production build: indexable, with the `https://admepro.com` canonical.
2. Upload to the main site's document root, or point `@`/`www` at the new folder.
3. Keep a full WordPress backup first.
4. Submit `https://admepro.com/sitemap.xml` in Google Search Console.
5. Connect the forms (INTEGRATION.md).
