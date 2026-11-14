<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class Authenticate implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (App::$user === null) {
            if ($request->wantsJson()) {
                return Response::error('Authentication required', 401);
            }
            if ($request->method === 'GET' && preg_match('#^/[A-Za-z0-9/_.-]{0,200}$#', $request->path) && !str_contains($request->path, '//')) {
                $_SESSION['_intended'] = $request->path;
            }
            if (!empty($_SESSION['_expired'])) {
                unset($_SESSION['_expired']);
                flash('warning', 'Your session expired. Please sign in again.');
            }
            return Response::redirect(url('login'));
        }
        return $next($request);
    }
}
