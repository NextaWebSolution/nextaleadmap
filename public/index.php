<?php

declare(strict_types=1);

/*
 * NextaLeadMap front controller. Point the web server's document root at /public,
 * or upload everything into public_html — the root .htaccess routes requests here.
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

$request = Request::capture();

try {
    if (!App::installed() && !str_starts_with($request->path, '/install')) {
        (Response::redirect(url('install')))->send($request);
        exit;
    }
    if (App::installed() && ($cfg = Env::configErrors())) {
        // Fail closed: never run with a broken key or missing settings. Details go to the log only.
        App::logger()->error('configuration invalid', ['problems' => $cfg]);
        $response = $request->wantsJson()
            ? Response::error('Service misconfigured. The administrator must fix .env (see storage/logs).', 503)
            : Response::html('<!DOCTYPE html><meta charset="utf-8"><title>Configuration error</title><p style="font:16px system-ui;margin:3rem">The application is misconfigured. The administrator must fix the <code>.env</code> file (details in <code>storage/logs</code>).</p>', 503);
        $response->send($request);
        exit;
    }
    $router = new Router();
    (require App::$root . '/src/routes.php')($router);
    $response = $router->dispatch($request);
} catch (Throwable $e) {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    App::logger()->error('Unhandled exception', [
        'ref' => $ref,
        'type' => $e::class,
        'msg' => $e->getMessage(),
        'at' => $e->getFile() . ':' . $e->getLine(),
        'path' => $request->path,
    ]);
    $msg = Env::bool('APP_DEBUG') ? $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : 'Something went wrong. Reference: ' . $ref;
    $response = $request->wantsJson() ? Response::error($msg, 500) : View::error(500, $msg);
}

$response->send($request);
