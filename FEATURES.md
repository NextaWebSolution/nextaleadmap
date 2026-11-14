# NextaLeadMap — Feature inventory

Source studied: `google-maps-scraper-kit-master` v2.1 (Python 3.9 engine + CLI, MIT).
This document maps every feature of the original kit to its PHP rebuild.

## 1. Original architecture (Python)

| Module | Role in the kit |
|---|---|
| `engine/serve.py` | stdlib HTTP server on 127.0.0.1:8080, REST API `POST/GET/DELETE /api/v1/jobs`, `/api/v1/jobs/{id}/download`, HTML form UI, optional `X-API-Key`, Host/Origin checks, city + business-type presets endpoints, GitHub update notice |
| `engine/jobs.py` | SQLite job store, single worker thread, job validation, per-keyword browser contexts, proxy rotation, 429/unusual-traffic back-off, watchdog on `max_time`, resume after restart (max 3), CSV append per batch, website pass (emails, socials, MX verify), SSRF-safe fetcher, formula-injection guard on CSV |
| `engine/maps.py` | Opens a Google Maps search in headless Chrome, scrolls the feed, intercepts `tbm=map` result batches |
| `engine/details.py` | Parses Google's internal place arrays (title, phone, website, rating, hours, plus code, images, about, menu…) |
| `engine/browser.py`, `cdp.py` | Launches Chrome / Chrome for Testing, Chrome DevTools Protocol client |
| `engine/website_browser.py` | Optional Playwright re-render of business websites for JS-only sites |
| `scripts/scrape.py` | CLI: `--city` geocode (Nominatim), `--depth`, `--grid N`, `--deep-scan` (split saturated tiles 2×2), `--tech` (ad pixels, CMS, mobile) + `intent_score`, `--verify-emails`, `--no-website`, `--no-socials`, `--exclude` previous results, dedupe, leads/full CSV |
| `scripts/leads.py` | Reduce a raw CSV to the 8 lead columns |
| `engine/presets/` | 168k GeoNames cities (CC BY 4.0) + 201 business types |

## 2. What changed and why

The kit gets its data by remote-controlling a real Chrome browser against Google Maps' own web page.
That cannot run in PHP alone (no browser, no CDP) and automated scraping of the Maps UI conflicts with
Google's Terms of Service. NextaLeadMap therefore collects place data through **licensed data providers**
whose credentials are configured in the admin panel:

* **Google Places API (New)** – official, Text Search, up to 60 results per search.
* **SerpApi Google Maps engine** – up to 120 results per search (same cap as the kit's browser engine).

Providers sit behind one `ProviderInterface`, so more (Outscraper, DataForSEO…) can be added as one class.
Everything after the place list — website crawling, emails, socials, tech signals, scoring, exports —
is reimplemented natively in PHP.

## 3. Feature map

| Kit feature | NextaLeadMap (PHP) | Where |
|---|---|---|
| Jobs with name, keywords (1–500), lang, country, lat/lon, zoom, depth, max_time | Same fields, validated server-side | `Services/JobService.php` |
| Geocode a city name | Local GeoNames table (autocomplete) + Nominatim or Google Geocoding | `Services/GeoService.php` |
| `--grid N` | N×N tiles over the city bbox, zoom/radius per tile | `GeoService::gridCells()` |
| `--deep-scan` | Saturated tiles split into 2×2 sub-tiles (2 levels) | `Services/JobRunner.php` |
| Dedupe by place id / cid / name+address | Unique `(job_id, place_key)` + cross-job exclusion | `PlaceRepository` |
| `--exclude` earlier results | "Skip places found in jobs…" multi-select | Job form / API `exclude_jobs` |
| `--no-website` | "Only businesses without a website" | Job option |
| Email crawl (homepage + contact/about pages, junk filter, own-domain first, max 3) | Same rules ported 1:1 | `Enrichment/EmailExtractor.php` |
| Owns-site check (phone or category on the page) | Ported | `Enrichment/WebsiteEnricher.php` |
| Socials: Instagram, Facebook, LinkedIn, TikTok, YouTube, X + skip handles | Ported regexes | `Enrichment/SocialExtractor.php` |
| `--verify-emails` (DoH MX lookup → google_workspace / microsoft_365 / other / none / nxdomain) | Native `dns_get_record()` | `Enrichment/MxVerifier.php` |
| `--tech` pixels, CMS, mobile + `intent_score` 0–100 | Ported formula | `Enrichment/TechDetector.php` |
| SSRF-safe fetch (public IPs only, redirect re-check, 400 KB cap, hard timeout) | cURL with DNS pinning (`CURLOPT_RESOLVE`), manual redirects, byte cap | `Core/HttpClient.php` |
| Proxy list rotation | Outbound proxy list in Settings → Providers | `HttpClient` |
| Back-off on 429 | Exponential retry per provider call | `Providers/AbstractProvider.php` |
| Watchdog / max_time | Deadline checked between batches; stale heartbeat recovery | `JobRunner` |
| Resume after crash (max 3) | `attempts` + `progress.cursor`, idempotent upserts | `JobRunner::recover()` |
| Downloads: Leads CSV, full CSV, JSON | Same three + filtered lead export | `Export/Exporter.php` |
| CSV formula-injection guard | Ported regex (phones untouched) | `Exporter::safe()` |
| Optional API key | Per-user API keys with scopes, hashed, revocable, rate limited | `ApiKeyRepository` |
| Web form UI | Full responsive admin dashboard | `views/` |
| Update notice (GitHub) | Signed auto-updater with backups + rollback | `Services/Update/` |
| Presets endpoints | `/api/v1/presets/cities`, `/api/v1/presets/business-types` | `Api/PresetApiController` |
| Playwright website rendering | Not ported (needs a browser runtime) | — |
| Google review text (`reviews_max`) | Not ported (not in Places Text Search; SerpApi needs extra paid calls) | — |

## 4. New in NextaLeadMap

Multi-user RBAC (roles + permission matrix), lead CRM status + notes, lead search across jobs,
dashboard analytics (Chart.js), audit log, DB-backed rate limiting, encrypted credential vault,
JSON settings export/import, web installer, cron or daemon worker, HTTP cron fallback, database
backups, signed updates with recovery, maintenance mode, cPanel `public_html` support.
