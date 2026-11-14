<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Csrf;
use App\Core\Icons;

if (!function_exists('e')) {
    /** HTML-escape any value for safe output in HTML text and attributes. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

/** JSON for embedding inside <script type="application/json"> or data-* attributes. */
function js(mixed $value): string
{
    return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function base_path(string $path = ''): string
{
    return App::$root . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function storage_path(string $path = ''): string
{
    return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

/** Absolute-path URL inside the app (handles sub-directory installs). */
function url(string $path = '', array $query = []): string
{
    $u = App::basePath() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u === '' ? '/' : $u;
}

function asset(string $path): string
{
    $file = base_path('public/assets/' . ltrim($path, '/'));
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : App::version();
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

function setting(string $key, mixed $default = null): mixed
{
    return App::settings()->get($key, $default);
}

function auth_user(): ?array
{
    return App::$user;
}

function can(string $permission): bool
{
    return App::auth()->can($permission);
}

function icon(string $name, string $class = 'w-5 h-5'): string
{
    return Icons::svg($name, $class);
}

/**
 * <script> tag for a pinned third-party library. Uses a self-hosted copy from public/assets/vendor when present
 * (php bin/console.php assets:vendor), otherwise the pinned jsDelivr URL.
 */
function vendor_script(string $name): string
{
    $map = \App\Core\Response::VENDOR[$name] ?? null;
    if (!$map) {
        return '';
    }
    [$file, $cdn] = $map;
    $local = base_path('public/assets/vendor/' . $file);
    $src = is_file($local) ? asset('vendor/' . $file) : $cdn;
    return '<script src="' . e($src) . '" nonce="' . e(nonce()) . '" defer' . (is_file($local) ? '' : ' crossorigin="anonymous"') . '></script>';
}

function nonce(): string
{
    return App::$cspNonce;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function errors(): array
{
    return $_SESSION['_errors'] ?? [];
}

function field_error(string $key): string
{
    $err = $_SESSION['_errors'][$key] ?? null;
    return $err ? '<p class="mt-1 text-xs text-rose-600">' . e($err) . '</p>' : '';
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Format a UTC datetime string in the app timezone. */
function fmt_date(?string $utc, string $format = 'd M Y, H:i'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone((string) setting('app.timezone', 'UTC')))->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function time_ago(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $s = time() - (int) strtotime($utc . ' UTC');
    return match (true) {
        $s < 60 => 'just now',
        $s < 3600 => intdiv($s, 60) . 'm ago',
        $s < 86400 => intdiv($s, 3600) . 'h ago',
        $s < 2592000 => intdiv($s, 86400) . 'd ago',
        default => fmt_date($utc, 'd M Y'),
    };
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function str_limit(?string $s, int $n = 60): string
{
    $s = (string) $s;
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

function human_bytes(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < 3) {
        $v /= 1024;
        $i++;
    }
    return round($v, 1) . ' ' . $u[$i];
}

function status_badge(string $status): string
{
    $map = [
        'pending' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'running' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'enriching' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'done' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'cancelled' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'new' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'contacted' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'qualified' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'won' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'lost' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'disabled' => 'bg-slate-100 text-slate-600 ring-slate-200',
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'rolled_back' => 'bg-amber-50 text-amber-700 ring-amber-200',
    ];
    $cls = $map[$status] ?? 'bg-slate-100 text-slate-700 ring-slate-200';
    return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ' . $cls . '">' . e(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}
