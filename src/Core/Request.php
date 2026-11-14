<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public array $attributes = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $files = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function capture(): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = App::basePath();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        if (str_starts_with($uri, '/public/')) {
            $uri = substr($uri, 7);
        }
        $uri = '/' . trim(rawurldecode($uri), '/');
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method']) && in_array(strtoupper((string) $_POST['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $_POST['_method']);
        }
        $raw = '';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $raw = (string) file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
        }
        return new self($method, $uri, $_GET, $_POST, $_SERVER, $_FILES, $raw);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Authorization') {
            return $this->server['HTTP_AUTHORIZATION'] ?? $this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        if ($name === 'Content-Type') {
            return $this->server['CONTENT_TYPE'] ?? null;
        }
        return $this->server[$key] ?? null;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower((string) $this->header('Content-Type')), 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || $this->isJson()
            || str_contains((string) $this->header('Accept'), 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    /** Body parameters: JSON body when sent as JSON, otherwise form fields. */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($this->isJson()) {
            if ($this->json === null) {
                $d = json_decode($this->rawBody, true, 32);
                $this->json = is_array($d) ? $d : [];
            }
            $data = $this->json;
        } else {
            $data = $this->post;
        }
        if ($key === null) {
            return $data;
        }
        return $data[$key] ?? $default;
    }

    public function str(string $key, string $default = '', int $max = 5000): string
    {
        $v = $this->input($key, $this->query[$key] ?? $default);
        if (!is_scalar($v)) {
            return $default;
        }
        $v = trim((string) $v);
        // strip control chars except newline/tab
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        return mb_substr($v, 0, $max);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $this->query[$key] ?? null);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->input($key, $this->query[$key] ?? null);
        return filter_var($v, FILTER_VALIDATE_BOOL);
    }

    public function q(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, 500) : $default;
    }

    public function isSecure(): bool
    {
        if (Env::bool('TRUST_PROXY') && strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') || (int) ($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    public function ip(): string
    {
        if (Env::bool('TRUST_PROXY')) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
                if (!empty($this->server[$h])) {
                    $ip = trim(explode(',', (string) $this->server[$h])[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
