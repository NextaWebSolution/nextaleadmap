# Signed updates

NextaLeadMap installs updates only from releases signed with **your** Ed25519 key.

## One-time setup (on your build machine)

```bash
php tools/release.php keygen
```

* Paste the **PUBLIC** key into `config/update-public-key.txt` (one key per line; several lines allow key rotation)
  and ship it in your next full release.
* Store the **SECRET** key offline (password manager / vault). Never upload it, never commit it.

## Publishing a release

```bash
echo "1.1.0" > VERSION
node tools/build-css.mjs                       # if views changed
php tools/release.php build --out=dist         # code only: .env, storage/, uploads are never packaged
NLM_SIGNING_KEY='…secret…' php tools/release.php sign dist/nextaleadmap-1.1.0.zip --notes="Improvements and security fixes" [--min-version=1.0.0]
php tools/release.php verify dist/manifest.json dist/nextaleadmap-1.1.0.zip
```

Upload `manifest.json` and the ZIP to any HTTPS server (e.g. `https://updates.nextawebsolution.com/nextaleadmap/`).
In the app: *Settings → Updates & backups → Update manifest URL* = `https://…/manifest.json`.

Manifest (the signature covers every other field, keys sorted, as canonical JSON):

```json
{
  "version": "1.1.0",
  "release_date": "2026-11-14",
  "minimum_php": "8.3",
  "archive": "nextaleadmap-1.1.0.zip",
  "checksum": "<sha256 of the zip>",
  "size": 3292827,
  "release_notes": "Improvements and security fixes",
  "signature": "<base64 Ed25519 signature>"
}
```

## What happens on "Install update"

1. Admin re-enters the password (and the request is CSRF-protected and audited).
2. Manifest fetched over HTTPS from a public host (SSRF-guarded) and its signature verified with the embedded key.
   The checksum is trusted **only** because it is inside the signed manifest.
3. Version must be newer, `minimum_php` satisfied, optional `min_version` respected.
4. ZIP downloaded (size-capped, HTTPS-only, redirects re-vetted) and its SHA-256 compared in constant time.
5. Extracted to `storage/updates/<version>/staging` with SafeZip: no absolute paths, `..`, backslashes, NUL bytes,
   symlinks, zip bombs, or paths outside the allow-list (`src/ views/ public/ bin/ vendor/ database/migrations/ …`).
   `.env`, `storage/`, `public/uploads/`, `config/local*.json` can never be written.
6. Maintenance mode on → database dump (`storage/backups/db-before-…sql.gz`) → backup ZIP of every file that will
   change (`storage/backups/files-…zip`), stored outside the extraction directory.
7. Each file replaced atomically (temp file in the same folder + `rename()`), then new migrations run.
8. Any error → migration batch rolled back, files restored from the backup, new files removed, maintenance off,
   history row marked `rolled_back`.
9. OPcache reset, staging removed, old backups pruned (`backup.keep`).

## Recovery

If PHP is killed mid-update (maintenance mode stays on):

```bash
php bin/console.php update:recover          # restores the files from the backup named in storage/maintenance.json
php bin/console.php migrate:status
php bin/console.php db:restore storage/backups/db-before-1.1.0-….sql.gz --force   # only if needed
php bin/console.php maintenance:off
```

Admins can also restore any file backup from **System → Backups**.
