<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ApiKeyRepository;
use App\Repositories\UserRepository;
use App\Services\Audit;
use Closure;

/**
 * REST API authentication: "Authorization: Bearer nlm_..." or "X-API-Key: nlm_...".
 * The key owner's role permissions AND the key's scopes both apply. Per-key and per-IP rate limits.
 */
final class ApiAuth implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        Audit::$ip = $request->ip();
        Audit::$userAgent = $request->userAgent();
        Audit::$via = 'api';
        $limiter = new RateLimiter(App::db());
        $perMin = max(1, (int) setting('security.api_rate_limit_per_minute', 60));

        $ipRl = $limiter->hit('api:ip:' . $request->ip(), $perMin * 3, 60);
        if (!$ipRl['allowed']) {
            return Response::error('Rate limit exceeded', 429)->header('Retry-After', (string) $ipRl['retry_after']);
        }
        if ($limiter->tooMany('api:badkey:' . $request->ip(), 20)) {
            Audit::log('security.api_key_bruteforce_blocked');
            return Response::error('Too many invalid API keys from this address. Try again later.', 429)->header('Retry-After', '600');
        }
        $auth = (string) $request->header('Authorization');
        $key = str_starts_with($auth, 'Bearer ') ? trim(substr($auth, 7)) : trim((string) $request->header('X-API-Key'));
        $row = $key !== '' ? (new ApiKeyRepository(App::db()))->authenticate($key) : null;
        if (!$row) {
            $limiter->hit('api:badkey:' . $request->ip(), 20, 600);
            return Response::error('Invalid or missing API key', 401)->header('WWW-Authenticate', 'Bearer');
        }
        if (App::maintenance()) {
            return Response::error('Service is under maintenance', 503)->header('Retry-After', '120');
        }
        $rl = $limiter->hit('api:key:' . $row['id'], $perMin, 60);
        if (!$rl['allowed']) {
            return Response::error('Rate limit exceeded', 429)->header('Retry-After', (string) $rl['retry_after'])
                ->header('X-RateLimit-Limit', (string) $rl['limit'])->header('X-RateLimit-Remaining', '0');
        }
        $user = (new UserRepository(App::db()))->find((int) $row['user_id']);
        if (!$user || $user['status'] !== 'active') {
            return Response::error('Invalid or missing API key', 401);
        }
        App::auth()->setUser($user);
        App::$apiKeyId = (string) $row['id'];
        $request->attributes['scopes'] = $row['scopes'];
        (new ApiKeyRepository(App::db()))->touch((int) $row['id'], $request->ip());

        return $next($request)
            ->header('X-RateLimit-Limit', (string) $rl['limit'])
            ->header('X-RateLimit-Remaining', (string) $rl['remaining']);
    }
}
