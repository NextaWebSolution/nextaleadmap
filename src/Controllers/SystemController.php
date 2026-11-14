<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\Audit;
use App\Services\Update\DatabaseBackup;
use App\Services\Update\Updater;
use App\Services\Worker;
use Throwable;

final class SystemController extends Controller
{
    private function updater(): Updater
    {
        return new Updater(App::$root);
    }

    private function confirmPassword(Request $r): bool
    {
        return App::auth()->verifyCurrentPassword((string) $r->input('confirm_password', ''), 'system:' . $r->path);
    }

    public function index(Request $r): Response
    {
        $backups = [];
        foreach (glob(storage_path('backups/*.{zip,gz}'), GLOB_BRACE) ?: [] as $f) {
            $backups[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => gmdate('Y-m-d H:i:s', (int) filemtime($f))];
        }
        usort($backups, static fn ($a, $b) => strcmp($b['time'], $a['time']));
        $pending = [];
        try {
            $pending = (new Migrator($this->db(), base_path('database/migrations')))->pending();
        } catch (Throwable) {
        }
        $cronToken = App::settings()->secret('cron.token');
        $counts = $this->db()->first("SELECT (SELECT COUNT(*) FROM {jobs} WHERE status = 'pending') pending, (SELECT COUNT(*) FROM {jobs} WHERE status IN ('running','enriching')) running, (SELECT COUNT(*) FROM {places}) places, (SELECT COUNT(*) FROM {users}) users");
        $env = App::$root . '/.env';
        $envPerm = is_file($env) ? (fileperms($env) & 0777) : 0;
        $https = str_starts_with(strtolower((string) \App\Core\Env::get('APP_URL', '')), 'https://');
        $seen = setting('worker.last_seen');
        $checks = [
            ['APP_URL uses https://', $https, 'Install an SSL certificate (AutoSSL in cPanel) and set APP_URL=https://… in .env.'],
            ['Force HTTPS enabled', (bool) setting('security.force_https'), 'Settings → Security → Force HTTPS (after HTTPS works).'],
            ['Debug mode off', !\App\Core\Env::bool('APP_DEBUG'), 'Set APP_DEBUG=false in .env.'],
            ['.env not world-readable', $envPerm !== 0 && ($envPerm & 0004) === 0, 'chmod 600 .env (or 640).'],
            ['Third-party scripts self-hosted', !array_filter(\App\Core\Response::VENDOR, static fn ($v) => !is_file(App::$root . '/public/assets/vendor/' . $v[0])), 'Run: php bin/console.php assets:vendor'],
            ['Update signing key configured', $this->updater()->verifier()->configured(), 'See UPDATES.md (php tools/release.php keygen).'],
            ['Worker ran in the last 5 minutes', is_string($seen) && strtotime($seen . ' UTC') > time() - 300, 'Add the cron job below.'],
            ['PHP version supported', version_compare(PHP_VERSION, '8.3.0', '>='), 'Use PHP 8.3 or 8.4.'],
        ];
        return $this->view('system/index', [
            'title' => 'System',
            'checks' => $checks,
            'info' => [
                'App version' => App::version(),
                'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
                'Database' => (string) $this->db()->value('SELECT VERSION()'),
                'Server' => php_uname('s') . ' ' . php_uname('r'),
                'Memory limit' => ini_get('memory_limit'),
                'Max execution time' => ini_get('max_execution_time') . ' s',
                'Password hashing' => defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt',
                'Update signing key' => $this->updater()->verifier()->configured() ? 'Configured' : 'Not configured (updates disabled)',
            ],
            'counts' => $counts,
            'backups' => $backups,
            'history' => $this->db()->all('SELECT h.*, u.name AS user_name FROM {update_history} h LEFT JOIN {users} u ON u.id = h.user_id ORDER BY h.id DESC LIMIT 10'),
            'pendingMigrations' => $pending,
            'cronCommand' => '* * * * * ' . (PHP_BINDIR ? PHP_BINDIR . '/php' : 'php') . ' ' . base_path('bin/worker.php') . ' --once >/dev/null 2>&1',
            'cronUrl' => $cronToken ? rtrim((string) \App\Core\Env::get('APP_URL', ''), '/') . '/cron/run?token=' . $cronToken : '',
            'workerSeen' => setting('worker.last_seen'),
            'lastCheck' => setting('updates.last_check'),
            'maintenance' => App::maintenance(),
        ]);
    }

    public function checkUpdate(Request $r): Response
    {
        try {
            $res = $this->updater()->check();
            Audit::log('update.checked', null, null, ['latest' => $res['manifest']['version'] ?? null]);
            if ($r->wantsJson()) {
                return Response::json(['ok' => true, 'available' => $res['available'], 'message' => $res['message'], 'version' => $res['manifest']['version'] ?? null, 'notes' => $res['manifest']['release_notes'] ?? '']);
            }
            flash($res['available'] ? 'success' : 'info', $res['message'] . (!empty($res['manifest']['release_notes']) ? ' — ' . str_limit((string) $res['manifest']['release_notes'], 200) : ''));
        } catch (Throwable $e) {
            Audit::log('update.check_failed', null, null, ['error' => $e->getMessage()]);
            if ($r->wantsJson()) {
                return Response::json(['ok' => false, 'message' => $e->getMessage()], 400);
            }
            flash('error', 'Update check failed: ' . $e->getMessage());
        }
        return Response::redirect(url('system'));
    }

    public function applyUpdate(Request $r): Response
    {
        if (!$this->confirmPassword($r)) {
            flash('error', 'Password confirmation failed.');
            return Response::redirect(url('system'));
        }
        @set_time_limit(900);
        @ignore_user_abort(true);
        $u = $this->updater();
        try {
            $u->apply($this->userId());
            Audit::log('update.applied', null, null, ['version' => App::version()]);
            flash('success', 'Update installed successfully. ' . str_replace("\n", ' · ', mb_substr($u->logText(), -300)));
        } catch (Throwable $e) {
            Audit::log('update.failed', null, null, ['error' => $e->getMessage()]);
            flash('error', 'Update failed: ' . $e->getMessage());
        }
        return Response::redirect(url('system'));
    }

    public function backupDb(Request $r): Response
    {
        @set_time_limit(600);
        $name = 'db-manual-' . gmdate('Ymd-His') . '.sql.gz';
        try {
            $rows = (new DatabaseBackup($this->db()))->dump(storage_path('backups/' . $name));
            Audit::log('backup.created', 'backup', $name, ['rows' => $rows]);
            flash('success', "Database backup created ($rows rows).");
        } catch (Throwable $e) {
            flash('error', 'Backup failed: ' . $e->getMessage());
        }
        return Response::redirect(url('system'));
    }

    private static function backupPath(string $name): ?string
    {
        if (!preg_match('/^(files|db)-[0-9A-Za-z.-]+\.(zip|sql\.gz)$/', $name)) {
            return null;
        }
        $p = storage_path('backups/' . $name);
        return is_file($p) ? $p : null;
    }

    public function downloadBackup(Request $r, string $name): Response
    {
        $p = self::backupPath($name);
        if (!$p) {
            return View::error(404, 'Backup not found');
        }
        Audit::log('backup.downloaded', 'backup', $name);
        return Response::download($name, 'application/octet-stream', static function ($out) use ($p) {
            $in = fopen($p, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        });
    }

    public function deleteBackup(Request $r, string $name): Response
    {
        $p = self::backupPath($name);
        if ($p) {
            unlink($p);
            Audit::log('backup.deleted', 'backup', $name);
            flash('success', 'Backup deleted.');
        }
        return Response::redirect(url('system'));
    }

    public function restoreBackup(Request $r): Response
    {
        if (!$this->confirmPassword($r)) {
            flash('error', 'Password confirmation failed.');
            return Response::redirect(url('system'));
        }
        $name = $r->str('backup', '', 200);
        if (!self::backupPath($name) || !str_starts_with($name, 'files-')) {
            flash('error', 'Choose a file backup to restore.');
            return Response::redirect(url('system'));
        }
        try {
            $this->updater()->maintenance(true, 'Restoring a backup…');
            $this->updater()->restore($name);
            Audit::log('backup.restored', 'backup', $name);
            flash('success', 'Application files restored from ' . $name . '. Restore the matching database backup with "php bin/console.php db:restore" if schema changed.');
        } catch (Throwable $e) {
            flash('error', 'Restore failed: ' . $e->getMessage());
        } finally {
            $this->updater()->maintenance(false);
        }
        return Response::redirect(url('system'));
    }

    public function maintenance(Request $r): Response
    {
        $on = $r->bool('on');
        $this->updater()->maintenance($on, $r->str('message', 'Scheduled maintenance. We will be back shortly.', 200));
        Audit::log($on ? 'system.maintenance_on' : 'system.maintenance_off');
        flash('success', $on ? 'Maintenance mode is ON. Admins can still use the app.' : 'Maintenance mode is OFF.');
        return Response::redirect(url('system'));
    }

    public function runWorker(Request $r): Response
    {
        @set_time_limit(60);
        @ignore_user_abort(true);
        $n = (new Worker())->runFor(25);
        Audit::log('system.worker_run', null, null, ['jobs' => $n]);
        flash('success', $n ? "Worker processed $n job(s) for 25 seconds. Long jobs continue on the next run." : 'No pending jobs.');
        return Response::back($r, 'system');
    }

    public function rotateCronToken(Request $r): Response
    {
        App::settings()->setSecret('cron.token', Crypto::randomToken(32), $this->userId());
        Audit::log('system.cron_token_rotated');
        flash('success', 'Cron URL token regenerated. Update your external cron service.');
        return Response::redirect(url('system'));
    }

    public function migrate(Request $r): Response
    {
        try {
            $res = (new Migrator($this->db(), base_path('database/migrations')))->migrate();
            Audit::log('system.migrated', null, null, $res);
            flash('success', $res['ran'] ? 'Ran: ' . implode(', ', $res['ran']) : 'Database is up to date.');
        } catch (Throwable $e) {
            flash('error', 'Migration failed: ' . $e->getMessage());
        }
        return Response::redirect(url('system'));
    }
}
