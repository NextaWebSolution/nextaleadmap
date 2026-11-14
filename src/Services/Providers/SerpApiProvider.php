<?php

declare(strict_types=1);

namespace App\Services\Providers;

use Generator;

/** SerpApi Google Maps engine — up to 120 results per query (6 pages of 20), same cap as Google Maps itself. */
final class SerpApiProvider extends AbstractProvider
{
    private const URL = 'https://serpapi.com/search.json';

    public function id(): string
    {
        return 'serpapi';
    }

    public function name(): string
    {
        return 'SerpApi (Google Maps)';
    }

    public function maxPerSearch(): int
    {
        return 120;
    }

    public function saturation(): int
    {
        return 100;
    }

    public function search(SearchQuery $q, callable $shouldStop): Generator
    {
        $limit = max(1, min($q->limit, $this->maxPerSearch()));
        $start = 0;
        while ($start < $limit && !$shouldStop()) {
            $params = ['engine' => 'google_maps', 'type' => 'search', 'q' => $q->keyword, 'hl' => $q->lang, 'start' => $start, 'api_key' => $this->apiKey];
            if ($q->country !== '') {
                $params['gl'] = strtolower($q->country);
            }
            if ($q->hasPoint()) {
                $params['ll'] = sprintf('@%.7F,%.7F,%dz', $q->lat, $q->lon, max(3, min(21, $q->zoom)));
            }
            $json = $this->call('GET', self::URL . '?' . http_build_query($params), ['Accept' => 'application/json'], null, $shouldStop);
            if (isset($json['error']) && !isset($json['local_results'])) {
                if (stripos((string) $json['error'], "hasn't returned any results") !== false) {
                    return;
                }
                throw new ProviderException('SerpApi: ' . $json['error'], true);
            }
            $results = $json['local_results'] ?? (isset($json['place_results']) ? [$json['place_results']] : []);
            $places = array_map([$this, 'normalize'], $results);
            yield ['places' => $places, 'calls' => 1];
            if (count($results) < 20 || empty($json['serpapi_pagination']['next'])) {
                return;
            }
            $start += 20;
        }
    }

    private function normalize(array $p): array
    {
        $title = (string) ($p['title'] ?? '');
        $pid = $p['place_id'] ?? null;
        $hours = null;
        if (isset($p['operating_hours']) && is_array($p['operating_hours'])) {
            $hours = implode('; ', array_map(static fn ($d, $h) => ucfirst((string) $d) . ': ' . (is_array($h) ? implode(', ', $h) : $h), array_keys($p['operating_hours']), $p['operating_hours']));
        }
        return [
            'place_key' => self::placeKey($pid, $title, $p['address'] ?? null),
            'provider_place_id' => $pid,
            'title' => $title,
            'category' => $p['type'] ?? null,
            'categories' => isset($p['types']) && is_array($p['types']) ? implode(', ', $p['types']) : null,
            'address' => $p['address'] ?? null,
            'phone' => $p['phone'] ?? null,
            'website' => $p['website'] ?? null,
            'rating' => isset($p['rating']) ? round((float) $p['rating'], 1) : null,
            'review_count' => isset($p['reviews']) ? (int) $p['reviews'] : null,
            'latitude' => $p['gps_coordinates']['latitude'] ?? null,
            'longitude' => $p['gps_coordinates']['longitude'] ?? null,
            'price_level' => $p['price'] ?? null,
            'business_status' => isset($p['open_state']) ? mb_substr((string) $p['open_state'], 0, 40) : null,
            'opening_hours' => $hours,
            'plus_code' => null,
            'maps_url' => $pid ? self::mapsUrl($title, $pid) : null,
            'thumbnail' => $p['thumbnail'] ?? null,
            'raw' => ['data_id' => $p['data_id'] ?? null, 'description' => $p['description'] ?? null],
        ];
    }

    protected function errorMessage(array $json, array $r): string
    {
        return 'SerpApi: ' . ($json['error'] ?? ('HTTP ' . $r['status'] . ($r['error'] ? ' (' . $r['error'] . ')' : '')));
    }
}
