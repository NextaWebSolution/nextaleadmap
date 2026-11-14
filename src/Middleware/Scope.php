<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** API scope + role permission check: new Scope('jobs:write', 'jobs.create'). */
final class Scope implements Middleware
{
    public function __construct(private string $scope, private string $permission)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($this->scope, $request->attributes['scopes'] ?? [], true)) {
            return Response::error('This API key lacks the "' . $this->scope . '" scope', 403);
        }
        if (!App::auth()->can($this->permission)) {
            return Response::error('The key owner lacks permission "' . $this->permission . '"', 403);
        }
        return $next($request);
    }
}
