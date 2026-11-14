<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Core\HttpClient;

abstract class AbstractProvider implements ProviderInterface
{
    public function __construct(protected HttpClient $http, protected string $apiKey, protected int $timeout = 30, protected int $maxRetries = 3)
    {
        if ($apiKey === '') {
            throw new ProviderException($this->name() . ' API key is not configured. Add it in Settings → Providers.', true);
        }
    }

    /**
     * HTTP with retries: 429 and 5xx back off exponentially (2, 4, 8… s, max 30 s); auth/quota errors fail fast.
     * @return array decoded JSON
     */
    protected function call(string $method, string $url, array $headers, ?string $body, callable $shouldStop): array
    {
        $attempt = 0;
        while (true) {
            $r = $this->http->api($method, $url, $headers, $body, $this->timeout);
            $json = json_decode($r['body'], true);
            if ($r['status'] >= 200 && $r['status'] < 300 && is_array($json)) {
                return $json;
            }
            $msg = $this->errorMessage(is_array($json) ? $json : [], $r);
            $retryable = $r['status'] === 0 || $r['status'] === 429 || $r['status'] >= 500;
            if (!$retryable) {
                throw new ProviderException($msg, in_array($r['status'], [400, 401, 403], true), $r['status']);
            }
            if (++$attempt > $this->maxRetries || $shouldStop()) {
                throw new ProviderException($msg . ' (gave up after ' . $attempt . ' attempts)', false, $r['status']);
            }
            $wait = min(30, 2 ** $attempt) + random_int(0, 1000) / 1000;
            if (isset($r['headers']['retry-after']) && is_numeric($r['headers']['retry-after'])) {
                $wait = min(60, (int) $r['headers']['retry-after']);
            }
            usleep((int) ($wait * 1_000_000));
        }
    }

    protected function errorMessage(array $json, array $r): string
    {
        return $this->name() . ' error: HTTP ' . $r['status'] . ($r['error'] ? ' (' . $r['error'] . ')' : '');
    }

    public static function mapsUrl(string $title, string $placeId): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($title) . '&query_place_id=' . rawurlencode($placeId);
    }

    public static function placeKey(?string $placeId, string $title, ?string $address): string
    {
        return $placeId ? 'g:' . $placeId : 't:' . md5(mb_strtolower($title . '|' . (string) $address));
    }

    public function test(): array
    {
        try {
            $gen = $this->search(new SearchQuery('coffee', 40.7128, -74.0060, 3000, 14, 'en', 'us', 1), static fn () => false);
            $n = 0;
            foreach ($gen as $batch) {
                $n += count($batch['places']);
                break;
            }
            return ['ok' => true, 'message' => 'Connected. Test search returned ' . $n . ' place(s).'];
        } catch (ProviderException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
