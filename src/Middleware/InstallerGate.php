<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use Closure;

/**
 * The installer only exists while storage/installed.lock is absent. Optional INSTALL_TOKEN in a pre-uploaded
 * .env must be presented once (?token=…). CSRF on every POST; a session-based throttle on attempts (no DB yet).
 */
final class InstallerGate implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (App::installed()) {
            return View::error(404, 'Page not found');
        }
        Session::start($request);
        $token = Env::get('INSTALL_TOKEN');
        if ($token) {
            if (isset($request->query['token']) && is_string($request->query['token']) && hash_equals($token, $request->query['token'])) {
                $_SESSION['install_ok'] = true;
            }
            if (empty($_SESSION['install_ok'])) {
                return View::error(403, 'Installer locked. Open /install?token=YOUR_INSTALL_TOKEN (see .env).');
            }
        }
        if ($request->method === 'POST') {
            if (!Csrf::valid(is_string($request->input('_token')) ? $request->input('_token') : null)) {
                return View::error(419, 'Session expired. Reload the installer and try again.');
            }
            $_SESSION['install_posts'] = ($_SESSION['install_posts'] ?? 0) + 1;
            if ($_SESSION['install_posts'] > 40) {
                return View::error(429, 'Too many attempts. Close the browser and start again.');
            }
        }
        return $next($request)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
