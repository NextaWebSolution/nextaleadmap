<?php

declare(strict_types=1);

namespace App\Services\Providers;

use Generator;

/** Google Places API (New) — Text Search. Official, up to 60 results per query (3 pages of 20). */
final class GooglePlacesProvider extends AbstractProvider
{
    private const URL = 'https://places.googleapis.com/v1/places:searchText';
    private const FIELDS = 'places.id,places.displayName,places.formattedAddress,places.internationalPhoneNumber,places.nationalPhoneNumber,'
        . 'places.websiteUri,places.rating,places.userRatingCount,places.location,places.primaryTypeDisplayName,places.types,'
        . 'places.googleMapsUri,places.businessStatus,places.priceLevel,places.regularOpeningHours.weekdayDescriptions,places.plusCode,nextPageToken';
    private const PRICE = ['PRICE_LEVEL_FREE' => 'Free', 'PRICE_LEVEL_INEXPENSIVE' => '$', 'PRICE_LEVEL_MODERATE' => '$$', 'PRICE_LEVEL_EXPENSIVE' => '$$$', 'PRICE_LEVEL_VERY_EXPENSIVE' => '$$$$'];

    public function id(): string
    {
        return 'google_places';
    }

    public function name(): string
    {
        return 'Google Places API';
    }

    public function maxPerSearch(): int
    {
        return 60;
    }

    public function saturation(): int
    {
        return 55;
    }

    public function search(SearchQuery $q, callable $shouldStop): Generator
    {
        $limit = max(1, min($q->limit, $this->maxPerSearch()));
        $token = null;
        $got = 0;
        do {
            $body = ['textQuery' => $q->keyword, 'pageSize' => min(20, $limit - $got), 'languageCode' => $q->lang];
            if ($q->country !== '') {
                $body['regionCode'] = strtoupper($q->country);
            }
            if ($q->hasPoint()) {
                $body['locationBias'] = ['circle' => ['center' => ['latitude' => $q->lat, 'longitude' => $q->lon], 'radius' => (float) max(100, min(50000, $q->radius))]];
            }
            if ($token) {
                $body['pageToken'] = $token;
            }
            $json = $this->call('POST', self::URL, [
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => self::FIELDS,
            ], (string) json_encode($body, JSON_UNESCAPED_UNICODE), $shouldStop);
            $places = array_map([$this, 'normalize'], $json['places'] ?? []);
            $got += count($places);
            yield ['places' => $places, 'calls' => 1];
            $token = $json['nextPageToken'] ?? null;
        } while ($token && $got < $limit && !$shouldStop());
    }

    private function normalize(array $p): array
    {
        $title = (string) ($p['displayName']['text'] ?? '');
        $id = (string) ($p['id'] ?? '');
        return [
            'place_key' => self::placeKey($id ?: null, $title, $p['formattedAddress'] ?? null),
            'provider_place_id' => $id ?: null,
            'title' => $title,
            'category' => $p['primaryTypeDisplayName']['text'] ?? null,
            'categories' => isset($p['types']) ? implode(', ', array_map(static fn ($t) => str_replace('_', ' ', (string) $t), $p['types'])) : null,
            'address' => $p['formattedAddress'] ?? null,
            'phone' => $p['internationalPhoneNumber'] ?? ($p['nationalPhoneNumber'] ?? null),
            'website' => $p['websiteUri'] ?? null,
            'rating' => isset($p['rating']) ? round((float) $p['rating'], 1) : null,
            'review_count' => isset($p['userRatingCount']) ? (int) $p['userRatingCount'] : null,
            'latitude' => $p['location']['latitude'] ?? null,
            'longitude' => $p['location']['longitude'] ?? null,
            'price_level' => self::PRICE[$p['priceLevel'] ?? ''] ?? null,
            'business_status' => isset($p['businessStatus']) ? strtolower((string) $p['businessStatus']) : null,
            'opening_hours' => isset($p['regularOpeningHours']['weekdayDescriptions']) ? implode('; ', $p['regularOpeningHours']['weekdayDescriptions']) : null,
            'plus_code' => $p['plusCode']['globalCode'] ?? null,
            'maps_url' => $p['googleMapsUri'] ?? ($id ? self::mapsUrl($title, $id) : null),
            'thumbnail' => null,
            'raw' => null,
        ];
    }

    protected function errorMessage(array $json, array $r): string
    {
        $m = $json['error']['message'] ?? null;
        return 'Google Places: ' . ($m ?: 'HTTP ' . $r['status'] . ($r['error'] ? ' (' . $r['error'] . ')' : ''));
    }
}
