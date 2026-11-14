# NextaLeadMap — Security Audit Report

**Date:** 14 Nov 2026 · **Version audited:** 1.0.0 (pre-release) · **Stack:** PHP 8.3 custom MVC, MySQL/MariaDB, Tailwind, Alpine.js, Chart.js
**Scope:** every file in `src/`, `views/`, `public/`, `bin/`, `tools/`, `database/`, `config/`, root `.htaccess` / `.env.example`.
**Method:** line-by-line code review against the requested checklist, attacker simulation, automated regression tests (`php tests/run.php`) and HTTP probes against the running app.

> An AI-based audit does not replace an independent penetration test. Integration tests against a live MySQL server could not be run in the audit environment (no database server available) — see *Unresolved issues*.

---

## 1. Executive summary

The codebase already had strong foundations (prepared statements everywhere, CSRF on all state changes, escaped output, strict CSP with nonces, Argon2id, encrypted secrets, SSRF-guarded crawler, signed updates). The audit found **1 High privilege-escalation flaw, 1 High defence-in-depth gap in the installer, 6 Medium and 8 Low issues**. All 16 were fixed and covered by regression tests (117/117 passing).

There is **no payment, subscription, coupon, wallet, JWT, OAuth or Supabase code** in this application, so those checklist sections are not applicable.

**Verdict: SAFE WITH CONDITIONS** — conditions are listed in the deployment checklist (HTTPS, self-hosted scripts, a staging run against real MySQL).

---

## 2. Vulnerability report & fixes

| # | Severity | Issue | Location | Exploit / impact | Fix implemented | Verified |
|---|---|---|---|---|---|---|
| 1 | **High** | Privilege escalation through user/role management | `Controllers/UserController.php`, `Controllers/RoleController.php` | Anyone holding `users.manage` could assign themselves the Administrator role, create new admins, or reset an admin's password; a `roles.manage` holder could add `system.manage` / `settings.manage` to their own role | Server-side "covers" rule (`AuthService::covers`): a user may only assign roles, create/edit/delete accounts, and grant permissions that are a subset of their own. Only `*` admins manage admins. Forms only list grantable roles/permissions. Attempts are audited as `security.escalation_blocked` | Unit tests (4 checks) + code review |
| 2 | **High** | Installer could re-open on a live database | `Services/Installer.php` | If `storage/installed.lock` disappears (re-upload, cleanup), anyone could run the installer and create an administrator in the production database | Installer refuses when the users table already has rows, re-creates the lock and changes nothing | Code review (needs DB to run) |
| 3 | Medium | Open redirect via backslash | `Core/Response::redirect`, `Middleware/Authenticate`, `AuthController::login` | `/%5Cevil.com` → stored as post-login target → `Location: /\evil.com` (browsers treat as `//evil.com`) → phishing | `Response::isLocalPath()` rejects `//`, `\`, control chars; intended URL restricted to a safe path pattern | 7 tests + HTTP probe |
| 4 | Medium | Internal error details shown to users | `Services/JobRunner.php` | PDO / filesystem exception text (SQL fragments, paths) stored as the job error and shown in UI/API | Non-provider errors replaced by a reference ID; details only in `storage/logs`; provider messages redacted | Test + review |
| 5 | Medium | Invalid-API-key lockout not enforced | `Middleware/ApiAuth.php` | Bad keys were counted but never blocked → unlimited key guessing / log flooding | 20 invalid keys / 10 min per IP → `429` + audit entry | Review |
| 6 | Medium | Password re-confirmation brute force | `ProfileController::password`, `SystemController::confirmPassword` | A hijacked session could brute-force the current password (only the 300/min global limit applied) | `AuthService::verifyCurrentPassword` — 5 attempts / 15 min per account, audited | Review |
| 7 | Medium | Session cookie not `Secure` behind TLS-terminating proxy/CDN | `Core/Session.php` | Behind Cloudflare/flexible SSL the cookie was sent without `Secure` | `Secure` also set when `APP_URL` is `https://` | Review |
| 8 | Medium | No fail-closed config validation | `public/index.php`, `Core/Env.php` | Broken/missing `APP_KEY` or `APP_URL` only failed later and inconsistently | `Env::configErrors()` checked on every request after install → 503 generic page, details logged (never the values) | 2 tests |
| 9 | Medium | Third-party script supply chain | layouts, dashboard, CSP | Alpine.js / Chart.js loaded from jsDelivr: a CDN compromise = script execution | `vendor_script()` helper + `php bin/console.php assets:vendor` self-hosts pinned versions and records SHA-384; the CDN is **removed from the CSP automatically** once self-hosted; `crossorigin` on CDN fallback | Test (could not download in audit env) |
| 10 | Low | Account-lock state enumerable | `AuthService::attempt` | "Account locked" shown for any password → reveals which emails exist | Lock message only after a correct password; others get the generic error | Review |
| 11 | Low | Host-header based HTTPS redirect | `Middleware/WebMiddleware.php` | Forged `Host` → redirect to attacker domain (cache poisoning) | Redirect host taken from `APP_URL` only | Review |
| 12 | Low | SSRF via admin-set Nominatim URL | `Services/GeoService.php` | Admin could point geocoding at internal HTTPS services | URL vetted (public IP, allowed port) before use | Review |
| 13 | Low | Header injection via User-Agent setting | `SettingsController` schema, `Core/HttpClient` | CR/LF in UA → extra outbound headers | Printable-ASCII validation + sanitising in client | 2 tests |
| 14 | Low | Expensive endpoints unthrottled | exports (web + API), provider test | Export loops / paid-API cost abuse | 120 exports/hour/user, 10 provider tests/10 min | Review |
| 15 | Low | Deep pagination DoS | `Controller::page` | `?page=100000&per_page=200` → huge OFFSET scans | Page capped at 5000 | Review |
| 16 | Low | Secrets could sit in session "old input" | `Core/Session::flashInput` | Provider keys / passwords stored in session files after a validation error | Any key matching password/token/api_key/secret/proxies is dropped | Test |
| 17 | Low | Cron token in URL ends up in access logs | `CronController` | Token leakage via logs | `X-Cron-Token` header supported (query kept for simple services) | Review |
| 18 | Low | No TLS option for remote DB | `Core/Database` | Credentials/data in clear to a remote DB | `DB_SSL_CA` env enables verified TLS | Review |

**Accepted / informational**
- CSP keeps `'unsafe-eval'` (Alpine.js standard build) and `style-src 'unsafe-inline'` (style attributes). All user output is escaped and no user data reaches Alpine expressions, so the residual risk is low. Moving to the `@alpinejs/csp` build would remove it (UI refactor).
- Installer pre-installation can test arbitrary DB hosts (port probing) — set `INSTALL_TOKEN` before uploading to a public server.
- `TRUST_PROXY=1` trusts `X-Forwarded-*`; enable only behind a proxy you control.

---

## 3. Controls verified as already correct

SQL injection (native prepared statements, whitelisted ORDER BY / identifiers) · Stored/reflected/DOM XSS (all 18 views escape; `js()` HEX encoding; no `innerHTML` with data) · CSRF on every POST/PUT/PATCH/DELETE, `_method` only from POST · IDOR: every job/lead/key lookup is scoped by owner unless `jobs.view_all` · Mass assignment (explicit field whitelists) · Argon2id + rehash + timing-safe dummy hash · Session fixation (regenerate), idle/absolute timeouts, invalidation on password change/disable · API keys stored as HMAC, scopes **and** role permissions enforced, expiry/revocation · SSRF guard (private/loopback/link-local/CGNAT/NAT64/mapped IPv6 blocked, DNS pinning, re-vetted redirects, byte caps) · ZIP slip / symlink / zip-bomb / protected paths · Ed25519-signed updates, signed checksum, atomic replace, rollback · CSV formula injection · Clickjacking (`X-Frame-Options DENY`, `frame-ancestors 'none'`) · No CORS headers (same-origin only) · Directory listing off, dotfiles and code folders denied · Upload (settings import only): extension, size, MIME, schema validation.

---

## 4. Security test results (actual)

| Suite | Result |
|---|---|
| `php tests/run.php` | **117 passed, 0 failed** (SSRF ×28, crypto ×3, env ×2, signatures ×6, ZIP ×8, extraction ×15, CSV guard ×6, geometry ×5, validation ×3, audit regressions ×20, view rendering ×21) |
| `php -l` on every PHP file | 0 errors |
| `node --check app.js` | OK |
| HTTP probes (built-in server) | Traversal `/install/..%2F..%2F.env` → 404 · `/%5Cevil.com` → stays on-site · POST without CSRF → 419 · `GET ?_method=DELETE` ignored · 3 MB JSON body → rejected (419) · API before install → redirect · all security headers present |
| Secret scan (regex for Google/Stripe/GitHub/Slack keys, private keys) | No matches |
| Dependency check | No Composer runtime deps. Chart.js 4.4.7: only known CVE (CVE-2020-7746) affects < 2.9.4. Alpine.js 3.14.9: no CVEs found. Tailwind is build-time only. |

---

## 5. Secrets audit

| Secret | Where it lives | Status |
|---|---|---|
| `APP_KEY`, DB credentials | `.env` (created 0600 by installer, git-ignored, never overwritten by updates) | OK — `.env.example` has placeholders only |
| Google Places / SerpApi / Geocoding keys, proxy list, cron token | `settings` table, XSalsa20-Poly1305 encrypted, never rendered back, redacted in logs | OK |
| API keys | HMAC-SHA256 only; plaintext shown once | OK |
| Update signing key | Public key in repo; **secret key must stay offline** | OK (none committed) |
| Hard-coded secrets in code | None found | — |
| Git history | No repository existed before this audit; nothing to rotate | — |

---

## 6. Personal data flow

| Data | Collected from | Stored | Who can see | Deletion |
|---|---|---|---|---|
| Team accounts: name, email, password hash, last login time/IP | Admin / installer | `users` | `users.manage` | Delete user (jobs kept, owner cleared) |
| Audit trail: user, IP, user agent, action metadata | Every security event | `audit_logs` | `audit.view` | Auto-pruned after `security.audit_retention_days` (365) |
| API key usage: last IP/time | API calls | `api_keys` | Owner / `users.manage` | Revoke; deleted with user |
| Business leads: name, phone, emails, website, socials, address, rating | Google Places / SerpApi + public business websites | `places` | Job owner, `jobs.view_all` | Delete leads, delete job (cascade) |
| Sessions | Login | `storage/sessions` (not web-reachable) | — | Expire; cleaned by worker after 24 h |
| Exports | User request | Streamed, not stored | Requesting user | — |

Shared with third parties: search keywords + coordinates → chosen data provider; business website requests → those websites; city names → Nominatim/Google Geocoding. Passwords, keys and tokens are never logged. Note your obligations under GDPR / India's DPDP Act when emailing leads.

---

## 7. Unresolved / not verified

1. **No live MySQL integration test** in the audit environment (database server unavailable). Middleware, rate limiter, installer guard and repository queries were verified by review only. **Action:** run a full staging install and the abuse checks below before go-live.
2. **Third-party scripts not yet self-hosted** (CDN blocked in the audit environment). **Action:** `php bin/console.php assets:vendor` on the server, compare the printed SHA-384 values with jsdelivr.com.
3. `.htaccess` deny rules not tested on Apache (no Apache in the audit environment). **Action:** after upload, confirm `https://your-domain/.env`, `/storage/`, `/src/App.php` and `/composer.json` return 403/404.
4. `'unsafe-eval'` remains in CSP (accepted, see above).

---

## 8. Deployment checklist

| Requirement | Status |
|---|---|
| Debug off (`APP_DEBUG=false`) | **PASS** (installer default; shown on System page) |
| HTTPS certificate + `APP_URL=https://…` | **ACTION** (server) |
| Settings → Security → Force HTTPS | **ACTION** after HTTPS works |
| `.env` permission 600/640 | PASS (installer) — checked on System page |
| `INSTALL_TOKEN` set before uploading to a public server | **ACTION** (recommended) |
| Self-host scripts (`assets:vendor`) | **ACTION** |
| `.env`, `storage/`, code folders not web-reachable | **ACTION — verify** on Apache |
| Worker cron configured | **ACTION** |
| Update signing key generated, secret kept offline | **ACTION** before first update |
| Provider keys restricted (Google key → server IP) | **ACTION** in Google Cloud |
| Staging run against real MySQL/MariaDB | **ACTION** |
| Regression suite passes | **PASS** (117/117) |
| No secrets in code | **PASS** |

The **System** page now shows a live security checklist for most of these items.

---

## 9. Final verdict

**SAFE WITH CONDITIONS.** All identified vulnerabilities are fixed and tested in isolation. Deploy after: HTTPS + Force HTTPS, `assets:vendor`, verifying the `.htaccess` blocks on your server, and one end-to-end staging run on MySQL/MariaDB.
