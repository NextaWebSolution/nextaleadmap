<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Core\App;
use App\Core\HttpClient;
use App\Core\Migrator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Signed, recoverable self-updater.
 *
 *  1. Fetch manifest from the configured HTTPS update server and verify its Ed25519 signature
 *     with the public key embedded in this installation.
 *  2. Download the archive (size-capped) and compare its SHA-256 with the *signed* checksum.
 *  3. Extract to storage/updates/<version>/staging with SafeZip (allow-list, no traversal, no symlinks).
 *  4. Maintenance mode on; database dump + backup ZIP of every file about to change → storage/backups/.
 *  5. Replace files one by one via same-directory temp file + rename() (atomic per file).
 *  6. Run new migrations. Any failure → restore files, roll back the migration batch, restore state.
 *  7. Clear OPcache, maintenance off, record history.
 *
 * Never touched: .env, storage/, public/uploads/, config/local*.json.
 */
final class Updater
{
    private const ALLOWED_ROOTS = ['src/', 'views/', 'public/', 'database/migrations/', 'database/presets/', 'bin/', 'vendor/', 'tools/', 'tests/', 'resources/', 'config/'];
    private const ALLOWED_FILES = ['bootstrap.php', '.gitignore', 'VERSION', 'composer.json', 'composer.lock', '.htaccess', '.env.example', 'README.md', 'INSTALL.md', 'UPDATES.md', 'SECURITY.md', 'FEATURES.md', 'CHANGELOG.md', 'index.php'];
    private const PROTECTED = ['#^\.env$#', '#^storage/#', '#^public/uploads/#', '#^config/local[^/]*$#', '#(^|/)\.git(/|$)#'];

    private array $log = [];

    public function __construct(private string $root)
    {
    }

    public static function allowedPath(string $rel): bool
    {
        foreach (self::PROTECTED as $rx) {
            if (preg_match($rx, $rel)) {
                return false;
            }
        }
        if (in_array($rel, self::ALLOWED_FILES, true)) {
            return true;
        }
        if (str_starts_with($rel, 'config/') && !in_array($rel, ['config/app.json', 'config/permissions.json', 'config/update-public-key.txt', 'config/.htaccess'], true)) {
            return false;
        }
        foreach (self::ALLOWED_ROOTS as $p) {
            if (str_starts_with($rel, $p)) {
                return true;
            }
        }
        return false;
    }

    private function note(string $m): void
    {
        $this->log[] = '[' . gmdate('H:i:s') . '] ' . $m;
    }

    public function logText(): string
    {
        return implode("\n", $this->log);
    }

    public function verifier(): ManifestVerifier
    {
        return ManifestVerifier::fromFile($this->root . '/config/update-public-key.txt');
    }

    /** @return array{available:bool, manifest:?array, current:string, message:string} */
    public function check(): array
    {
        $current = App::version();
        $url = trim((string) setting('updates.server_url', ''));
        if ($url === '') {
            return ['available' => false, 'manifest' => null, 'current' => $current, 'message' => 'No update server is configured.'];
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            return ['available' => false, 'manifest' => null, 'current' => $current, 'message' => 'The update server URL must use HTTPS.'];
        }
        $http = new HttpClient([], 'NextaLeadMap-Updater/' . $current);
        $m = $this->verifier()->verify($http->getJsonPublic($url));
        $newer = version_compare($m['version'], $current, '>');
        $phpOk = version_compare(PHP_VERSION, $m['minimum_php'], '>=');
        $msg = $newer ? ($phpOk ? 'Version ' . $m['version'] . ' is available.' : 'Version ' . $m['version'] . ' needs PHP ' . $m['minimum_php'] . ' (you run ' . PHP_VERSION . ').') : 'You are on the latest version.';
        App::settings()->set('updates.last_check', ['at' => now_utc(), 'latest' => $m['version']]);
        return ['available' => $newer && $phpOk, 'manifest' => $m, 'current' => $current, 'message' => $msg];
    }

    /** Full update. Returns the history row id. */
    public function apply(?int $userId): int
    {
        $current = App::version();
        $db = App::db();
        $histId = (int) $db->insert('update_history', ['from_version' => $current, 'to_version' => '?', 'status' => 'running', 'user_id' => $userId, 'created_at' => now_utc()]);
        $state = ['replaced' => [], 'added' => [], 'backup' => null, 'batch' => 0];
        try {
            $chk = $this->check();
            $m = $chk['manifest'];
            if (!$m || !$chk['available']) {
                throw new RuntimeException($chk['message']);
            }
            if (!empty($m['min_version']) && version_compare($current, (string) $m['min_version'], '<')) {
                throw new RuntimeException('Update to ' . $m['min_version'] . ' first.');
            }
            $db->update('update_history', ['to_version' => $m['version']], 'id = ?', [$histId]);
            $this->note('Manifest signature verified for ' . $m['version']);

            $work = $this->root . '/storage/updates/' . preg_replace('/[^0-9A-Za-z.-]/', '', $m['version']);
            $this->rrmdir($work);
            mkdir($work . '/staging', 0755, true);
            $pkg = $work . '/package.zip';
            $archiveUrl = $this->resolveArchiveUrl((string) setting('updates.server_url'), $m['archive']);
            $max = max(5, (int) setting('updates.max_package_mb', 100)) * 1024 * 1024;
            (new HttpClient([], 'NextaLeadMap-Updater/' . $current))->downloadTo($archiveUrl, $pkg, $max);
            $sha = hash_file('sha256', $pkg);
            if (!hash_equals(strtolower($m['checksum']), $sha)) {
                throw new RuntimeException('Checksum mismatch: the downloaded package does not match the signed manifest.');
            }
            $this->note('Package downloaded and checksum verified (' . human_bytes((int) filesize($pkg)) . ')');

            $files = (new SafeZip())->extract($pkg, $work . '/staging', [self::class, 'allowedPath']);
            $staged = trim((string) @file_get_contents($work . '/staging/VERSION'));
            if ($staged !== $m['version']) {
                throw new RuntimeException('Package VERSION (' . $staged . ') does not match the manifest (' . $m['version'] . ')');
            }
            $this->note(count($files) . ' files staged');

            $this->maintenance(true, 'Updating to ' . $m['version'] . '…', ['stage' => 'backup']);
            $stamp = gmdate('Ymd-His');
            if (setting('updates.db_backup', true)) {
                $dbFile = $this->root . "/storage/backups/db-before-{$m['version']}-$stamp.sql.gz";
                $n = (new DatabaseBackup($db))->dump($dbFile);
                $this->note("Database backup written ($n rows)");
            }
            $backup = $this->root . "/storage/backups/files-{$current}-to-{$m['version']}-$stamp.zip";
            $state['backup'] = basename($backup);
            $this->writeBackup($backup, $files, $current, $m['version']);
            $this->maintenance(true, 'Updating to ' . $m['version'] . '…', ['stage' => 'replacing', 'backup' => basename($backup)]);
            $this->note('File backup written: ' . basename($backup));

            foreach ($files as $rel) {
                $target = $this->root . '/' . $rel;
                $existed = is_file($target);
                $this->atomicCopy($work . '/staging/' . $rel, $target);
                if ($existed) {
                    $state['replaced'][] = $rel;
                } else {
                    $state['added'][] = $rel;
                }
            }
            $this->note(count($state['replaced']) . ' files replaced, ' . count($state['added']) . ' added');

            $res = (new Migrator($db, $this->root . '/database/migrations'))->migrate();
            $state['batch'] = $res['batch'];
            $this->note($res['ran'] ? 'Migrations: ' . implode(', ', $res['ran']) : 'No database migrations');

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $this->rrmdir($work);
            $this->pruneBackups();
            $this->maintenance(false);
            $db->update('update_history', ['status' => 'success', 'backup_file' => $state['backup'], 'migration_batch' => $state['batch'] ?: null, 'log' => $this->logText(), 'finished_at' => now_utc()], 'id = ?', [$histId]);
            return $histId;
        } catch (Throwable $e) {
            $this->note('ERROR: ' . $e->getMessage());
            $status = 'failed';
            if ($state['replaced'] || $state['added']) {
                try {
                    if ($state['batch']) {
                        (new Migrator($db, $this->root . '/database/migrations'))->rollback($state['batch']);
                        $this->note('Migration batch rolled back');
                    }
                    $this->restore($state['backup']);
                    $status = 'rolled_back';
                    $this->note('Files restored from backup');
                } catch (Throwable $e2) {
                    $this->note('RESTORE FAILED: ' . $e2->getMessage() . ' — run: php bin/console.php update:recover');
                    $db->update('update_history', ['status' => 'failed', 'backup_file' => $state['backup'], 'log' => $this->logText(), 'finished_at' => now_utc()], 'id = ?', [$histId]);
                    throw new RuntimeException('Update failed and automatic restore failed. Maintenance mode stays on. Run "php bin/console.php update:recover".', 0, $e);
                }
            }
            $this->maintenance(false);
            $db->update('update_history', ['status' => $status, 'backup_file' => $state['backup'], 'log' => $this->logText(), 'finished_at' => now_utc()], 'id = ?', [$histId]);
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function resolveArchiveUrl(string $manifestUrl, string $archive): string
    {
        if (preg_match('#^https://#i', $archive)) {
            return $archive;
        }
        if (preg_match('#^[a-z]+:#i', $archive) || str_contains($archive, '..')) {
            throw new RuntimeException('Archive location not allowed');
        }
        $p = parse_url($manifestUrl);
        $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
        return 'https://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $dir . ltrim($archive, '/');
    }

    private function writeBackup(string $file, array $files, string $from, string $to): void
    {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Cannot create backup archive');
        }
        $replaced = [];
        $added = [];
        foreach ($files as $rel) {
            $abs = $this->root . '/' . $rel;
            if (is_file($abs)) {
                $zip->addFile($abs, 'files/' . $rel);
                $replaced[] = $rel;
            } else {
                $added[] = $rel;
            }
        }
        $zip->addFromString('backup.json', (string) json_encode(['from' => $from, 'to' => $to, 'created_at' => gmdate('c'), 'replaced' => $replaced, 'added' => $added], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!$zip->close()) {
            throw new RuntimeException('Backup archive could not be written (disk full?)');
        }
    }

    /** Restore code files from a backup ZIP made by writeBackup(); removes files the update added. */
    public function restore(?string $backupName): void
    {
        if (!$backupName || !preg_match('/^files-[0-9A-Za-z.-]+\.zip$/', $backupName)) {
            throw new RuntimeException('Invalid backup name');
        }
        $file = $this->root . '/storage/backups/' . $backupName;
        $tmp = $this->root . '/storage/tmp/restore-' . bin2hex(random_bytes(4));
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Backup archive not found or unreadable');
        }
        $meta = json_decode((string) $zip->getFromName('backup.json'), true);
        $zip->close();
        if (!is_array($meta)) {
            throw new RuntimeException('Backup metadata missing');
        }
        $files = (new SafeZip())->extract($file, $tmp, static fn ($p) => $p === 'backup.json' || (str_starts_with($p, 'files/') && self::allowedPath(substr($p, 6))));
        foreach ($files as $rel) {
            if (str_starts_with($rel, 'files/')) {
                $this->atomicCopy($tmp . '/' . $rel, $this->root . '/' . substr($rel, 6));
            }
        }
        foreach ($meta['added'] ?? [] as $rel) {
            if (is_string($rel) && SafeZip::cleanPath($rel) === $rel && self::allowedPath($rel) && is_file($this->root . '/' . $rel)) {
                @unlink($this->root . '/' . $rel);
            }
        }
        $this->rrmdir($tmp);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /** CLI recovery after a crash mid-update (maintenance.json still says "replacing"). */
    public function recover(): string
    {
        $m = App::maintenance();
        if (!$m || ($m['stage'] ?? '') !== 'replacing' || empty($m['backup'])) {
            $this->maintenance(false);
            return 'Nothing to recover. Maintenance mode cleared.';
        }
        $this->restore((string) $m['backup']);
        $this->maintenance(false);
        return 'Restored files from ' . $m['backup'] . '. If migrations ran, check "php bin/console.php migrate:status".';
    }

    private function atomicCopy(string $src, string $dest): void
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Cannot create directory ' . $dir);
        }
        $tmp = $dest . '.nlm-' . bin2hex(random_bytes(3));
        if (!copy($src, $tmp)) {
            throw new RuntimeException('Cannot write ' . $dest);
        }
        @chmod($tmp, 0644);
        if (!rename($tmp, $dest)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot replace ' . $dest);
        }
    }

    public function maintenance(bool $on, string $message = '', array $extra = []): void
    {
        $f = $this->root . '/storage/maintenance.json';
        if ($on) {
            file_put_contents($f, (string) json_encode(['message' => $message, 'since' => gmdate('c')] + $extra), LOCK_EX);
        } elseif (is_file($f)) {
            @unlink($f);
        }
    }

    private function pruneBackups(): void
    {
        $keep = max(1, (int) setting('backup.keep', 5));
        foreach (['files-*.zip', 'db-before-*.sql.gz'] as $pattern) {
            $list = glob($this->root . '/storage/backups/' . $pattern) ?: [];
            usort($list, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($list, $keep) as $old) {
                @unlink($old);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $storage = realpath($this->root . '/storage');
        $real = realpath($dir);
        if ($storage === false || $real === false || !str_starts_with($real, $storage . '/')) {
            return; // only ever delete inside storage/
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }
}
