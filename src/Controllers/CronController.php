<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\Worker;

/**
 * HTTP cron for hosts without CLI cron: GET /cron/run?token=… (token from System page, compared in constant time).
 * Prefer the CLI worker when possible; this endpoint runs the queue for ~25 s per call.
 */
final class CronController extends Controller
{
    public function run(Request $r): Response
    {
        $limiter = new RateLimiter(App::db());
        if (!$limiter->hit('cron:' . $r->ip(), 6, 60)['allowed']) {
            return Response::error('Too many requests', 429);
        }
        $expected = App::settings()->secret('cron.token');
        // Prefer the X-Cron-Token header (kept out of access logs); ?token= remains for simple cron services.
        $given = (string) ($r->header('X-Cron-Token') ?? $r->q('token'));
        if ($expected === '' || !hash_equals($expected, $given)) {
            return Response::error('Forbidden', 403);
        }
        @set_time_limit(60);
        @ignore_user_abort(true);
        $n = (new Worker())->runFor(25);
        return Response::json(['ok' => true, 'jobs_processed' => $n, 'at' => gmdate('c')]);
    }

    public function health(Request $r): Response
    {
        try {
            App::db()->value('SELECT 1');
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        }
        return Response::json(['status' => $ok ? 'ok' : 'degraded', 'maintenance' => App::maintenance() !== null], $ok ? 200 : 503);
    }
}
