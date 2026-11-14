<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Core\App;
use App\Core\HttpClient;

final class ProviderFactory
{
    /** id => [class, label, description] */
    public const PROVIDERS = [
        'google_places' => [GooglePlacesProvider::class, 'Google Places API (New)', 'Official Google data. Up to 60 results per search. Billing-enabled Google Cloud key with Places API (New).'],
        'serpapi' => [SerpApiProvider::class, 'SerpApi — Google Maps', 'Up to 120 results per search, same ranking as Google Maps. Paid SerpApi plan.'],
    ];

    public static function enabled(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $id => $meta) {
            if (setting("providers.$id.enabled", false) && App::settings()->hasSecret("providers.$id.api_key")) {
                $out[$id] = $meta[1];
            }
        }
        return $out;
    }

    public static function proxies(): array
    {
        $raw = App::settings()->secret('providers.proxies');
        $list = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
        return array_values(array_filter($list, static fn ($p) => (bool) preg_match('#^(https?|socks5h?)://[^\s]+$#i', $p)));
    }

    public static function make(string $id, ?string $apiKeyOverride = null): ProviderInterface
    {
        if (!isset(self::PROVIDERS[$id])) {
            throw new ProviderException('Unknown provider: ' . $id, true);
        }
        $proxies = setting('providers.use_proxies') ? self::proxies() : [];
        $http = new HttpClient($proxies, 'NextaLeadMap/' . App::version());
        $class = self::PROVIDERS[$id][0];
        return new $class($http, $apiKeyOverride ?? App::settings()->secret("providers.$id.api_key"), max(5, (int) setting('providers.timeout', 30)), max(0, (int) setting('providers.max_retries', 3)));
    }
}
