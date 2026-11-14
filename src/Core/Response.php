<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** Pinned third-party scripts: local file name => CDN fallback. */
    public const VENDOR = [
        'alpine' => ['alpinejs-3.14.9.min.js', 'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js'],
        'chart' => ['chartjs-4.4.7.umd.min.js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js'],
    ];

    /** @var null|callable */
    private $stream = null;

    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        return new self((string) $body, $status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    public static function error(string $message, int $status, array $extra = []): self
    {
        return self::json(['error' => ['code' => $status, 'message' => $message] + $extra], $status);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        if (!self::isLocalPath($to)) {
            $to = url('/'); // open-redirect protection
        }
        return new self('', $status, ['Location' => $to]);
    }

    /**
     * Same-site path only: must start with a single "/", and may not contain backslashes or control
     * characters (browsers treat "/\\evil.com" like "//evil.com").
     */
    public static function isLocalPath(string $to): bool
    {
        return $to !== '' && $to[0] === '/' && !str_starts_with($to, '//')
            && !str_contains($to, '\\') && !preg_match('/[\x00-\x1F\x7F]/', $to);
    }

    public static function back(Request $r, string $fallback = '/'): self
    {
        $ref = (string) ($r->server['HTTP_REFERER'] ?? '');
        $host = (string) ($r->server['HTTP_HOST'] ?? '');
        $p = parse_url($ref);
        if ($ref !== '' && isset($p['host']) && strcasecmp($p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $host) === 0) {
            return self::redirect(($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : ''));
        }
        return self::redirect(url($fallback));
    }

    /** Stream a download built by $writer(resource $out). */
    public static function download(string $filename, string $mime, callable $writer): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'download';
        $r = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'no-store',
        ]);
        $r->stream = $writer;
        return $r;
    }

    public function header(string $k, string $v): self
    {
        $this->headers[$k] = $v;
        return $this;
    }

    public function send(Request $request): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach (self::securityHeaders($request) + $this->headers as $k => $v) {
                header($k . ': ' . str_replace(["\r", "\n"], '', $v));
            }
        }
        if ($this->stream) {
            $out = fopen('php://output', 'wb');
            ($this->stream)($out);
            fclose($out);
            return;
        }
        echo $this->body;
    }

    public static function securityHeaders(Request $request): array
    {
        $n = App::$cspNonce;
        // The CDN is only allowed while a library is not self-hosted yet.
        $cdn = '';
        foreach (self::VENDOR as [$file]) {
            if (!is_file(App::$root . '/public/assets/vendor/' . $file)) {
                $cdn = ' https://cdn.jsdelivr.net';
            }
        }
        $csp = "default-src 'self'; "
            . "script-src 'self' 'nonce-$n' 'unsafe-eval'$cdn; "
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; "
            . "connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'";
        $h = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(self), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy' => $csp,
        ];
        if ($request->isApi()) {
            $h['Cache-Control'] = 'no-store';
        }
        if ($request->isSecure()) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return $h;
    }
}
