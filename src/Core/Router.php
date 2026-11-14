<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Small regex router with per-route middleware stacks.
 * Middleware: object with handle(Request $r, Closure $next): Response
 */
final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    public function group(array $attrs, Closure $define): void
    {
        $this->groupStack[] = $attrs;
        $define($this);
        array_pop($this->groupStack);
    }

    public function get(string $path, array|Closure $handler, array $mw = []): void
    {
        $this->add(['GET', 'HEAD'], $path, $handler, $mw);
    }

    public function post(string $path, array|Closure $handler, array $mw = []): void
    {
        $this->add(['POST'], $path, $handler, $mw);
    }

    public function put(string $path, array|Closure $handler, array $mw = []): void
    {
        $this->add(['PUT', 'PATCH'], $path, $handler, $mw);
    }

    public function delete(string $path, array|Closure $handler, array $mw = []): void
    {
        $this->add(['DELETE'], $path, $handler, $mw);
    }

    private function add(array $methods, string $path, array|Closure $handler, array $mw): void
    {
        $prefix = '';
        $stack = [];
        foreach ($this->groupStack as $g) {
            $prefix .= $g['prefix'] ?? '';
            $stack = array_merge($stack, $g['middleware'] ?? []);
        }
        $full = '/' . trim($prefix . $path, '/');
        $regex = '#^' . preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function ($m) {
            $pattern = $m[2] ?? '[^/]+';
            return '(?P<' . $m[1] . '>' . $pattern . ')';
        }, $full) . '$#';
        $this->routes[] = ['methods' => $methods, 'regex' => $regex, 'handler' => $handler, 'mw' => array_merge($stack, $mw)];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if (!in_array($request->method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $core = function (Request $r) use ($route, $params): Response {
                $h = $route['handler'];
                if ($h instanceof Closure) {
                    return $h($r, ...$params);
                }
                [$class, $method] = $h;
                return (new $class())->$method($r, ...array_values($params));
            };
            $pipeline = array_reduce(
                array_reverse($route['mw']),
                static fn (Closure $next, $mw) => static fn (Request $r) => (is_string($mw) ? new $mw() : $mw)->handle($r, $next),
                $core
            );
            return $pipeline($request);
        }
        if ($allowed) {
            return $request->wantsJson()
                ? Response::error('Method not allowed', 405)
                : View::error(405, 'Method not allowed');
        }
        return $request->wantsJson() ? Response::error('Not found', 404) : View::error(404, 'Page not found');
    }
}
