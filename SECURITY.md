# Security model

| Control | Implementation |
|---|---|
| SQL injection | PDO with native prepared statements everywhere (`ATTR_EMULATE_PREPARES=false`); identifiers validated by regex; `LIKE` input escaped |
| XSS | Every view escapes with `e()` (`htmlspecialchars` ENT_QUOTES/HTML5); JSON for scripts via `js()` with HEX flags; strict CSP with per-request nonce, no inline event handlers; user URLs re-validated before becoming links (`rel="noopener noreferrer nofollow"`) |
| CSRF | Per-session 256-bit token, checked on every POST/PUT/PATCH/DELETE (form field or `X-CSRF-Token`), `hash_equals` |
| Passwords | Argon2id (bcrypt cost 12 fallback), rehash on login, strength + length policy, constant-time dummy verify for unknown users |
| Sessions | Strict mode, 288-bit IDs, HttpOnly + SameSite=Lax + Secure (on HTTPS), regeneration on login/password change, idle and absolute timeouts, sessions invalidated after a password change or account disable |
| Login abuse | DB-backed rate limits per IP and per email, temporary account lock, audit of failures |
| Authorization | Role-based permissions checked by middleware on every route; data scoping so non-managers only see their own jobs/leads; last-admin protection; anti-escalation rule — managers can only assign roles/permissions they hold themselves |
| API | Keys `nlm_<prefix>_<secret>` stored as HMAC-SHA256 (keyed by APP_KEY), shown once, scopes + role permissions both required, expiry, revocation, per-key and per-IP rate limits, `401`/`403`/`429` JSON errors |
| Secrets at rest | Provider keys, proxy lists, cron token encrypted with XSalsa20-Poly1305 (libsodium) using `APP_KEY`; never rendered back to the browser; redacted in logs and audit entries |
| SSRF | Website crawler and updater only reach http(s) on ports 80/443/8080/8443, after resolving every A/AAAA record and rejecting private, loopback, link-local, CGNAT, multicast, NAT64 and IPv4-mapped addresses; the connection is pinned to the vetted IP (`CURLOPT_RESOLVE`, no DNS rebinding); redirects are followed manually and re-vetted; byte caps and hard timeouts |
| Uploads | Settings import only: `.json`, ≤256 KB, MIME sniffed, `is_uploaded_file`, every key validated against the schema, secrets never importable |
| Path traversal | Template names whitelisted by regex; backup names whitelisted; SafeZip normalises and re-checks real paths; deletes confined to `storage/` |
| Updates | Ed25519-signed manifests, embedded public key, signed checksum, allow-listed paths, atomic replacement, backups + automatic rollback (see UPDATES.md) |
| Headers | CSP, X-Frame-Options DENY, frame-ancestors 'none', nosniff, Referrer-Policy, Permissions-Policy, COOP, HSTS on HTTPS, `no-store` on app pages |
| Files | Only `public/` is web-served; `.htaccess` deny rules in every other folder; `.env` written with 0600 |
| CSV exports | Formula-injection guard (`=`, `+`, `-`, `@`, tab, CR prefixed with `'`; phone numbers left intact) |
| Audit | Logins, failures, CSRF rejections, forbidden access, settings/role/user/key changes, exports, updates, backups — with user, IP, user agent and channel |

Latest audit: see SECURITY-AUDIT.md.

Report vulnerabilities to the Nexta Web Solution security contact; do not open public issues.
