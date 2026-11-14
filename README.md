# NextaLeadMap

**Self-hosted business lead research & enrichment platform** — by Nexta Web Solution.
Pure PHP 8.3+ / MySQL rebuild of the Python *google-maps-scraper-kit*: search businesses by keyword and city,
then enrich every lead with emails, social profiles, MX checks and marketing-tech signals. Runs on cPanel shared hosting.

* PHP 8.3 / 8.4 · MySQL 8+ / MariaDB 10.6+ · PDO · Tailwind CSS v4 · Alpine.js · Chart.js
* No framework, no Node.js or Python at runtime
* See **FEATURES.md** for the full inventory of the original kit and how each feature maps to this rebuild.

## Features

| Area | What you get |
|---|---|
| Search jobs | Many keywords per job, city autocomplete (168k GeoNames cities), coordinates or "use my location", N×N coverage grid, **deep scan** that splits saturated tiles, results-per-search slider, language/country, max run time, "skip places from earlier jobs", "only businesses without a website" |
| Data providers | Google Places API (New) and SerpApi (Google Maps), keys stored **encrypted** and managed in the admin panel, optional rotating proxies, retry with back-off |
| Enrichment | Homepage + contact/about pages fetched concurrently through an SSRF-safe client: up to 3 emails (junk filtered), Instagram / Facebook / LinkedIn / TikTok / YouTube / X, MX host verification, Meta pixel / Google Ads / analytics / CMS / mobile detection, 0–100 intent score, "is this really their website?" check |
| Leads CRM | Search & filter across all jobs, pipeline status (new → contacted → qualified → won/lost), notes, bulk actions, CSV / full CSV / JSON exports with spreadsheet formula-injection guard |
| Dashboard | KPIs, 30-day chart, top categories, pipeline |
| Team | Users, roles with an editable permission matrix, per-user data scoping, audit log |
| REST API | `/api/v1` with scoped, hashed, expiring API keys and per-key rate limits |
| Operations | Web installer, cron or daemon worker (resumable jobs), HTTP cron fallback, DB backups, signed auto-updater with automatic rollback, maintenance mode, JSON settings import/export |

---

## Install on cPanel (public_html)

1. **Database:** cPanel → *MySQL® Databases* → create a database and a user, add the user to the database with *ALL PRIVILEGES*.
2. **PHP version:** cPanel → *MultiPHP Manager* (or *Select PHP Version*) → PHP **8.3** or 8.4. Enable extensions:
   `pdo_mysql, curl, mbstring, openssl, sodium, zip, zlib, intl, fileinfo`.
3. **Upload:** File Manager → `public_html` (or a sub-folder / sub-domain folder) → upload the ZIP → *Extract*.
   The root `.htaccess` sends every request to `/public` and blocks access to code, `.env` and `storage/`.
   *Better:* point a sub-domain's document root directly at the `public/` folder.
4. **Permissions:** folders `755`, files `644`; `storage/` and its sub-folders must be writable.
5. **Install:** open `https://your-domain/` → the installer checks requirements, tests the database,
   writes `.env` (with a random `APP_KEY`, chmod 600), creates tables, the admin account and imports the city list.
6. **Cron:** cPanel → *Cron Jobs* → *Once Per Minute*:
   ```
   /usr/local/bin/php /home/USER/public_html/bin/worker.php --once >/dev/null 2>&1
   ```
   (The exact command with your paths is shown on **System**. Some hosts use `/opt/cpanel/ea-php83/root/usr/bin/php`.)
   No cron available? Use the token-protected HTTP cron URL from the System page with any external cron service.
7. **Provider key:** *Settings → Data providers* → paste your Google Places (New) or SerpApi key → *Test*.

> Back up `.env`. Its `APP_KEY` decrypts the stored provider keys; never change it after install.

### Optional hardening on public servers
Set `INSTALL_TOKEN=some-long-random-string` in a `.env` you upload before installing; the installer then only opens at
`/install?token=…`. Set `TRUST_PROXY=1` only behind Cloudflare / a reverse proxy you control.

## Install on a VPS (Nginx + PHP-FPM)

```nginx
server {
    server_name leads.example.com;
    root /var/www/nextaleadmap/public;
    index index.php;
    location / { try_files $uri /index.php?$query_string; }
    location ~ ^/index\.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_param HTTP_AUTHORIZATION $http_authorization; }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

Worker as a service (`/etc/systemd/system/nextaleadmap-worker.service`):

```ini
[Unit]
Description=NextaLeadMap worker
After=network.target mysql.service
[Service]
User=www-data
ExecStart=/usr/bin/php /var/www/nextaleadmap/bin/worker.php --daemon
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
```

## How jobs run

`keywords × grid tiles` searches are queued. The worker claims jobs with `SELECT … FOR UPDATE SKIP LOCKED`
(several workers are safe), saves progress after every result batch and yields when its time budget ends, so a job
continues on the next cron tick. A job whose worker died is resumed automatically (up to `jobs.max_attempts`).
Deep scan splits any tile that returned close to the provider cap (Google ≈ 60, SerpApi ≈ 120) into four sub-tiles,
up to two levels deep. After searching, the website pass enriches every lead that has a website.

## REST API

Create a key under **API keys** (choose scopes, optional expiry). Send it as `Authorization: Bearer nlm_…`.

```bash
curl -X POST https://leads.example.com/api/v1/jobs \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"keywords":["dentists"],"location_mode":"city","city":"Pune, Maharashtra","grid":2,"emails":true,"tech":true}'
curl -H "Authorization: Bearer $KEY" https://leads.example.com/api/v1/jobs/JOB_ID
curl -H "Authorization: Bearer $KEY" -o leads.csv "https://leads.example.com/api/v1/jobs/JOB_ID/download?format=leads"
curl -H "Authorization: Bearer $KEY" "https://leads.example.com/api/v1/leads?has_email=yes&min_rating=4"
curl -X PATCH -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -d '{"lead_status":"contacted"}' https://leads.example.com/api/v1/leads/123
```

`GET /api/v1` lists every endpoint. Responses carry `X-RateLimit-Limit` / `X-RateLimit-Remaining`; errors are
`{"error":{"code":…,"message":…}}`.

## Console

```
php bin/console.php migrate | migrate:status | migrate:rollback
php bin/console.php user:create --email=a@b.com --name="Admin" --role=admin
php bin/console.php user:password --email=a@b.com        # reset + unlock
php bin/console.php worker:run --seconds=55
php bin/console.php update:check | update:apply | update:recover
php bin/console.php backup:db | db:restore storage/backups/db-….sql.gz --force
php bin/console.php maintenance:on | maintenance:off
php tests/run.php                                          # self-test (97 checks)
```

## Updates

See **UPDATES.md** — signed releases (Ed25519), checksum from the signed manifest, safe extraction, backups, atomic
replacement, reversible migrations and automatic rollback.

## Front-end build (optional)

The compiled `public/assets/css/app.css` ships with the app. After changing views, rebuild with Tailwind v4:
`npm i -D tailwindcss@4 && node tools/build-css.mjs`. Alpine.js 3.14 and Chart.js 4.4 load from jsDelivr
(pinned versions, allowed by the Content-Security-Policy).

## Legal

Use the data in line with your provider's terms (Google Maps Platform / SerpApi), anti-spam law (CAN-SPAM, GDPR,
India's DPDP Act) and the websites you crawl. City presets © GeoNames, CC BY 4.0. Original kit: MIT License.
