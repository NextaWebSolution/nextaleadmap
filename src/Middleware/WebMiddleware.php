<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Audit;
use Closure;

/**
 * Web stack in one place: session start, user load, maintenance gate, per-IP rate limit,
 * HTTPS enforcement and CSRF verification for every state-changing request.
 */
final class WebMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (setting('security.force_https') && !$request->isSecure()) {
            // Use the configured APP_URL host, never the client-supplied Host header (no redirect poisoning).
            $host = (string) (parse_url((string) \App\Core\Env::get('APP_URL', ''), PHP_URL_HOST) ?: '');
            $port = parse_url((string) \App\Core\Env::get('APP_URL', ''), PHP_URL_PORT);
            $uri = (string) ($request->server['REQUEST_URI'] ?? '/');
            if ($host === '' || !Response::isLocalPath($uri)) {
                return View::error(400, 'HTTPS is required. Set APP_URL in .env to your https:// address.');
            }
            return new Response('', 301, ['Location' => 'https://' . $host . ($port ? ':' . $port : '') . $uri]);
        }
        Session::start($request);
        Audit::$ip = $request->ip();
        Audit::$userAgent = $request->userAgent();
        App::auth()->loadFromSession();

        $limit = (int) setting('security.web_rate_limit_per_minute', 300);
        if ($limit > 0) {
            $rl = (new RateLimiter(App::db()))->hit('web:' . $request->ip(), $limit, 60);
            if (!$rl['allowed']) {
                return ($request->wantsJson() ? Response::error('Too many requests', 429) : View::error(429, 'Too many requests. Slow down a little.'))
                    ->header('Retry-After', (string) $rl['retry_after']);
            }
        }

        $m = App::maintenance();
        if ($m && !App::auth()->can('system.manage') && !in_array($request->path, ['/login', '/logout'], true)) {
            return Response::html(View::render('errors/maintenance', ['message' => $m['message'] ?? 'We are updating the application.', 'title' => 'Maintenance'], 'layouts/auth'), 503)
                ->header('Retry-After', '120');
        }

        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->header('X-CSRF-Token') ?? (is_string($request->input('_token')) ? $request->input('_token') : null);
            if (!Csrf::valid($token)) {
                Audit::log('security.csrf_rejected', null, null, ['path' => $request->path]);
                return $request->wantsJson()
                    ? Response::error('Your session expired. Reload the page and try again.', 419)
                    : View::error(419, 'Your session expired. Go back, reload the page and try again.');
            }
        }

        $response = $next($request);
        if (!$request->wantsJson() && $request->method === 'GET') {
            Session::clearInput();
        }
        return $response->header('Cache-Control', 'no-store, private');
    }
}
