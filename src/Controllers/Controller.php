<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return View::page($template, $data, $status);
    }

    protected function db(): \App\Core\Database
    {
        return App::db();
    }

    protected function userId(): int
    {
        return (int) (App::$user['id'] ?? 0);
    }

    protected function scope(): ?int
    {
        return App::auth()->scope();
    }

    protected function page(Request $r): int
    {
        return max(1, min(5000, $r->int('page', 1))); // bounded OFFSET (no deep-scan DoS)
    }

    protected function perPage(Request $r): int
    {
        $pp = $r->int('per_page', (int) setting('app.per_page', 25));
        return max(5, min(200, $pp));
    }

    /** Validate; on failure redirect back with errors (web) or 422 (JSON). */
    protected function validate(Request $r, array $rules): ?Response
    {
        $data = (array) $r->input();
        $v = Validator::make($data, $rules);
        if (!$v->fails()) {
            return null;
        }
        if ($r->wantsJson()) {
            return Response::error('Validation failed', 422, ['fields' => $v->errors]);
        }
        Session::flashInput($data, $v->errors);
        return Response::back($r);
    }

    protected function failBack(Request $r, array $errors): Response
    {
        if ($r->wantsJson()) {
            return Response::error('Validation failed', 422, ['fields' => $errors]);
        }
        Session::flashInput((array) $r->input(), $errors);
        flash('error', (string) reset($errors));
        return Response::back($r);
    }

    /** Per-user throttle for expensive endpoints (exports, paid provider calls). */
    protected function throttle(Request $r, string $bucket, int $max, int $window): ?Response
    {
        $rl = (new \App\Core\RateLimiter($this->db()))->hit($bucket . ':' . $this->userId(), $max, $window);
        if ($rl['allowed']) {
            return null;
        }
        $msg = 'Limit reached for this action. Try again in ' . max(1, (int) ceil($rl['retry_after'] / 60)) . ' minute(s).';
        return ($r->wantsJson() ? Response::error($msg, 429) : View::error(429, $msg))->header('Retry-After', (string) $rl['retry_after']);
    }

    protected static function pagination(int $total, int $page, int $per): array
    {
        return ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int) ceil($total / max(1, $per)))];
    }
}
