<?php

declare(strict_types=1);

/*
 * NextaLeadMap background worker.
 *
 *   php bin/worker.php --once            Run for jobs.worker_max_runtime seconds (default 55), then exit. Use from cron:
 *                                        * * * * * php /home/USER/public_html/bin/worker.php --once >/dev/null 2>&1
 *   php bin/worker.php --daemon          Loop forever (VPS: systemd / supervisor). Sleeps 3 s when idle.
 *   php bin/worker.php --once --max-runtime=300
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Services\Worker;

$opts = getopt('', ['once', 'daemon', 'max-runtime:', 'quiet']);
if (!App::installed()) {
    fwrite(STDERR, "Not installed yet. Open the web installer first.\n");
    exit(1);
}

// One cron worker at a time per server is plenty; extra ones exit quietly. Daemons skip this lock.
$lock = null;
if (!isset($opts['daemon'])) {
    $lock = fopen(App::$root . '/storage/tmp/worker-cron.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0);
    }
}

$quiet = isset($opts['quiet']);
$log = static function (string $m) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $m . "\n");
    }
};
@set_time_limit(0);
$worker = new Worker(Closure::fromCallable($log));

if (isset($opts['daemon'])) {
    $stop = false;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use (&$stop) { $stop = true; });
        pcntl_signal(SIGINT, static function () use (&$stop) { $stop = true; });
    }
    $log('worker daemon started (pid ' . getmypid() . ')');
    while (!$stop) {
        try {
            if ($worker->runFor(300) === 0) {
                sleep(3);
            }
        } catch (Throwable $e) {
            App::logger()->error('worker loop error', ['e' => $e->getMessage()]);
            $log('error: ' . $e->getMessage());
            sleep(10);
            App::setDb(App\Core\Database::connect([
                'host' => App\Core\Env::get('DB_HOST'), 'port' => (int) App\Core\Env::get('DB_PORT', '3306'),
                'database' => App\Core\Env::get('DB_DATABASE'), 'username' => App\Core\Env::get('DB_USERNAME'),
                'password' => App\Core\Env::get('DB_PASSWORD'), 'prefix' => App\Core\Env::get('DB_PREFIX', ''),
            ]));
        }
    }
    $log('worker stopped');
    exit(0);
}

$runtime = isset($opts['max-runtime']) ? (int) $opts['max-runtime'] : (int) setting('jobs.worker_max_runtime', 55);
try {
    $n = $worker->runFor(max(10, $runtime));
    $log("done: $n job(s) processed");
} catch (Throwable $e) {
    App::logger()->error('worker error', ['e' => $e->getMessage()]);
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
