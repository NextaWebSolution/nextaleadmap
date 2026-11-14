<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\AuditRepository;
use App\Repositories\JobRepository;

/**
 * Background worker. Two ways to run it:
 *   - cron (cPanel):  * * * * *  php /path/bin/worker.php --once        (works for ~55 s, then exits)
 *   - daemon (VPS):   php bin/worker.php --daemon  (under systemd / supervisor)
 * Several workers may run at once: jobs are claimed with SELECT … FOR UPDATE SKIP LOCKED.
 */
final class Worker
{
    public function __construct(private ?\Closure $log = null)
    {
    }

    private function log(string $m): void
    {
        if ($this->log) {
            ($this->log)('[' . gmdate('H:i:s') . '] ' . $m);
        }
    }

    /** Process jobs until $seconds are used up or the queue is empty. Returns jobs touched. */
    public function runFor(int $seconds): int
    {
        $end = time() + max(10, $seconds);
        $repo = new JobRepository(App::db());
        $recovered = $repo->recoverStale(max(2, (int) setting('jobs.stale_minutes', 5)), max(1, (int) setting('jobs.max_attempts', 3)));
        if ($recovered) {
            $this->log("recovered $recovered stale job(s)");
        }
        App::settings()->set('worker.last_seen', now_utc());
        $this->housekeeping();
        $workerId = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(3));
        $n = 0;
        while (time() < $end - 3) {
            if (App::maintenance()) {
                $this->log('maintenance mode: not starting jobs');
                break;
            }
            $job = $repo->claim($workerId);
            if (!$job) {
                break;
            }
            $n++;
            $this->log('running job ' . $job['id'] . ' "' . $job['name'] . '"');
            $state = (new JobRunner($this->log))->run($job, $end - time());
            $this->log('job ' . $job['id'] . ' → ' . $state);
        }
        return $n;
    }

    /** Notify-only daily check: stores the latest version for the System page; never installs by itself. */
    private function dailyUpdateCheck(): void
    {
        if (!setting('updates.auto_check', true) || trim((string) setting('updates.server_url', '')) === '') {
            return;
        }
        $last = setting('updates.last_check');
        if (is_array($last) && strtotime(($last['at'] ?? '1970-01-01') . ' UTC') > time() - 86400) {
            return;
        }
        try {
            $r = (new \App\Services\Update\Updater(App::$root))->check();
            $this->log('update check: ' . $r['message']);
        } catch (\Throwable $e) {
            App::settings()->set('updates.last_check', ['at' => now_utc(), 'latest' => '?', 'error' => mb_substr($e->getMessage(), 0, 200)]);
            $this->log('update check failed: ' . $e->getMessage());
        }
    }

    private function housekeeping(): void
    {
        $this->dailyUpdateCheck();
        if (random_int(1, 30) !== 1) {
            return;
        }
        (new AuditRepository(App::db()))->prune((int) setting('security.audit_retention_days', 365));
        foreach (glob(storage_path('exports/*')) ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400 && basename($f) !== '.gitkeep') {
                @unlink($f);
            }
        }
        foreach (glob(storage_path('sessions/sess_*')) ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}
