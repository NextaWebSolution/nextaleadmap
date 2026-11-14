<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\HttpClient;
use App\Repositories\CityRepository;
use RuntimeException;

/** Geocoding (local presets → Nominatim / Google Geocoding) and the grid / deep-scan tiling ported from scrape.py. */
final class GeoService
{
    /**
     * @return array{label:string, lat:float, lon:float, bbox:array{0:float,1:float,2:float,3:float}, country:string}
     */
    public function geocode(string $place): array
    {
        $place = trim($place);
        if ($place === '') {
            throw new RuntimeException('Enter a city or area.');
        }
        $provider = (string) setting('geocoding.provider', 'nominatim');
        try {
            if ($provider === 'google' && App::settings()->hasSecret('geocoding.google_api_key')) {
                return $this->google($place);
            }
            if ($provider !== 'local') {
                return $this->nominatim($place);
            }
        } catch (RuntimeException $e) {
            App::logger()->warning('geocoding fallback to local presets', ['e' => $e->getMessage()]);
        }
        $hits = (new CityRepository(App::db()))->search($place, 1);
        if (!$hits) {
            throw new RuntimeException('Could not find "' . $place . '". Try "City, Region" or pick a suggestion.');
        }
        $h = $hits[0];
        return ['label' => $h['label'], 'lat' => $h['lat'], 'lon' => $h['lon'], 'bbox' => self::bboxAround($h['lat'], $h['lon'], (float) setting('geocoding.city_radius_km', 12)), 'country' => $h['country']];
    }

    private function nominatim(string $place): array
    {
        $base = rtrim((string) setting('geocoding.nominatim_url', 'https://nominatim.openstreetmap.org'), '/');
        HttpClient::vet($base . '/search'); // admin-configurable URL: must resolve to a public address (SSRF guard)
        $contact = (string) setting('geocoding.contact_email', '');
        $http = new HttpClient([], 'NextaLeadMap/' . App::version() . ($contact ? ' (' . $contact . ')' : ''));
        $r = $http->api('GET', $base . '/search?' . http_build_query(['q' => $place, 'format' => 'jsonv2', 'limit' => 1, 'addressdetails' => 1]), ['Accept' => 'application/json'], null, 15);
        $d = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($d) || !$d) {
            throw new RuntimeException('Nominatim could not geocode the place (HTTP ' . $r['status'] . ')');
        }
        $h = $d[0];
        $bb = array_map('floatval', $h['boundingbox'] ?? []);
        if (count($bb) !== 4) {
            $bb = self::bboxAround((float) $h['lat'], (float) $h['lon'], 10);
        }
        return ['label' => (string) ($h['display_name'] ?? $place), 'lat' => (float) $h['lat'], 'lon' => (float) $h['lon'], 'bbox' => $bb, 'country' => strtolower((string) ($h['address']['country_code'] ?? ''))];
    }

    private function google(string $place): array
    {
        $http = new HttpClient([], 'NextaLeadMap/' . App::version());
        $r = $http->api('GET', 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(['address' => $place, 'key' => App::settings()->secret('geocoding.google_api_key')]), [], null, 15);
        $d = json_decode($r['body'], true);
        $h = $d['results'][0] ?? null;
        if (!$h) {
            throw new RuntimeException('Google Geocoding: ' . ($d['status'] ?? 'no result'));
        }
        $vp = $h['geometry']['bounds'] ?? $h['geometry']['viewport'];
        $country = '';
        foreach ($h['address_components'] ?? [] as $c) {
            if (in_array('country', $c['types'] ?? [], true)) {
                $country = strtolower((string) $c['short_name']);
            }
        }
        return [
            'label' => (string) $h['formatted_address'],
            'lat' => (float) $h['geometry']['location']['lat'], 'lon' => (float) $h['geometry']['location']['lng'],
            'bbox' => [(float) $vp['southwest']['lat'], (float) $vp['northeast']['lat'], (float) $vp['southwest']['lng'], (float) $vp['northeast']['lng']],
            'country' => $country,
        ];
    }

    /** [south, north, west, east] around a point. */
    public static function bboxAround(float $lat, float $lon, float $km): array
    {
        $dLat = $km / 111.32;
        $dLon = $km / (111.32 * max(0.01, cos(deg2rad($lat))));
        return [max(-90, $lat - $dLat), min(90, $lat + $dLat), max(-180, $lon - $dLon), min(180, $lon + $dLon)];
    }

    /**
     * Split a bbox into n×n cells. radius (m) = 1.15 × half the cell diagonal (≥1000);
     * zoom = the Maps zoom whose ~1000 px viewport is about as wide as the cell (11..17). Port of grid_cells().
     */
    public static function gridCells(array $bbox, int $n, int $level = 0): array
    {
        [$s, $nn, $w, $e] = array_map('floatval', $bbox);
        $n = max(1, $n);
        $dlat = ($nn - $s) / $n;
        $dlon = ($e - $w) / $n;
        $mPerDeg = 6371000 * M_PI / 180;
        $cells = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $lat = $s + ($i + 0.5) * $dlat;
                $lon = $w + ($j + 0.5) * $dlon;
                $cos = cos(deg2rad($lat));
                $width = max(1.0, $dlon * $mPerDeg * $cos);
                $diag = hypot($dlat * $mPerDeg, $width);
                $zoom = (int) round(log(156543.03 * $cos * 1000 / $width, 2));
                $cells[] = [
                    'lat' => round($lat, 6), 'lon' => round($lon, 6),
                    'radius' => max(1000, (int) ceil(1.15 * $diag / 2)),
                    'zoom' => min(17, max(11, $zoom)),
                    'bbox' => [$s + $i * $dlat, $s + ($i + 1) * $dlat, $w + $j * $dlon, $w + ($j + 1) * $dlon],
                    'level' => $level,
                ];
            }
        }
        return $cells;
    }

    /** Deep scan: one saturated tile → four sub-tiles one zoom level in. */
    public static function split(array $cell): array
    {
        return self::gridCells($cell['bbox'], 2, (int) ($cell['level'] ?? 0) + 1);
    }

    /** Inside bbox grown by $margin of its size on each side; rows without coordinates are kept. */
    public static function inBbox(array $row, array $bbox, float $margin = 0.10): bool
    {
        if (!is_numeric($row['latitude'] ?? null) || !is_numeric($row['longitude'] ?? null)) {
            return true;
        }
        [$s, $n, $w, $e] = $bbox;
        $my = ($n - $s) * $margin;
        $mx = ($e - $w) * $margin;
        $lat = (float) $row['latitude'];
        $lon = (float) $row['longitude'];
        return $lat >= $s - $my && $lat <= $n + $my && $lon >= $w - $mx && $lon <= $e + $mx;
    }
}
