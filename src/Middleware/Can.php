<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Audit;
use Closure;

/** Permission gate: new Can('jobs.create'). Any of several: new Can('a', 'b'). */
final class Can implements Middleware
{
    private array $permissions;

    public function __construct(string ...$permissions)
    {
        $this->permissions = $permissions;
    }

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->permissions as $p) {
            if (App::auth()->can($p)) {
                return $next($request);
            }
        }
        Audit::log('security.forbidden', null, null, ['path' => $request->path, 'needs' => $this->permissions]);
        return $request->wantsJson() ? Response::error('You do not have permission for this action', 403) : View::error(403, 'You do not have permission to access this page.');
    }
}
