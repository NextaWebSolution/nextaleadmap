<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\RateLimiter;
use App\Repositories\JobRepository;
use App\Services\Providers\ProviderFactory;
use RuntimeException;

/** Validates and creates search jobs (web form and REST API share this). */
final class JobService
{
    /**
     * @return array{ok:bool, id?:string, errors?:array<string,string>}
     */
    public function create(array $in, int $userId): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        $kw = $in['keywords'] ?? [];
        if (is_string($kw)) {
            $kw = preg_split('/\R/', $kw) ?: [];
        }
        $keywords = [];
        foreach ((array) $kw as $k) {
            $k = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $k) ?? '');
            if ($k !== '' && mb_strlen($k) <= 200) {
                $keywords[mb_strtolower($k)] = $k;
            }
        }
        $keywords = array_values($keywords);
        $maxKw = max(1, (int) setting('jobs.max_keywords', 100));
        if (!$keywords) {
            $errors['keywords'] = 'Add at least one keyword, e.g. "dentists".';
        } elseif (count($keywords) > $maxKw) {
            $errors['keywords'] = "At most $maxKw keywords per job.";
        }
        if ($name === '') {
            $name = $keywords ? mb_substr(implode(', ', array_slice($keywords, 0, 3)), 0, 150) : 'Search';
        }
        if (mb_strlen($name) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            $errors['name'] = 'Name: 200 characters at most, no control characters.';
        }

        $enabled = ProviderFactory::enabled();
        $provider = (string) ($in['provider'] ?? setting('providers.default', 'google_places'));
        if (!isset($enabled[$provider])) {
            $errors['provider'] = $enabled ? 'Choose an enabled data provider.' : 'No data provider is configured. An admin must add an API key in Settings → Providers.';
        }

        $lang = strtolower(trim((string) ($in['lang'] ?? setting('app.default_lang', 'en'))));
        if (!preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $lang)) {
            $errors['lang'] = 'Language must be a code like en or pt-br.';
        }
        $country = strtolower(trim((string) ($in['country'] ?? '')));
        if ($country !== '' && !preg_match('/^[a-z]{2}$/', $country)) {
            $errors['country'] = 'Country must be a 2-letter code such as in, us or de.';
        }

        $mode = (string) ($in['location_mode'] ?? (isset($in['city']) && $in['city'] !== '' ? 'city' : ((isset($in['lat']) && $in['lat'] !== '') ? 'coords' : 'none')));
        $loc = ['mode' => 'none', 'label' => '', 'lat' => null, 'lon' => null, 'bbox' => null];
        $gridMax = max(1, (int) setting('jobs.grid_max', 6));
        $grid = max(1, min($gridMax, (int) ($in['grid'] ?? 1)));
        if ($mode === 'city') {
            $city = trim((string) ($in['city'] ?? ''));
            if ($city === '') {
                $errors['city'] = 'Enter a city or area.';
            } elseif (isset($in['city_lat'], $in['city_lon']) && is_numeric($in['city_lat']) && is_numeric($in['city_lon'])) {
                $lat = (float) $in['city_lat'];
                $lon = (float) $in['city_lon'];
                $loc = ['mode' => 'city', 'label' => $city, 'lat' => $lat, 'lon' => $lon, 'bbox' => GeoService::bboxAround($lat, $lon, (float) setting('geocoding.city_radius_km', 12))];
                $cc = strtolower((string) ($in['city_country'] ?? ''));
                if ($country === '' && preg_match('/^[a-z]{2}$/', $cc)) {
                    $country = $cc;
                }
            } else {
                try {
                    $g = (new GeoService())->geocode($city);
                    $loc = ['mode' => 'city', 'label' => $g['label'], 'lat' => $g['lat'], 'lon' => $g['lon'], 'bbox' => $g['bbox']];
                    if ($country === '' && $g['country'] !== '') {
                        $country = $g['country'];
                    }
                } catch (RuntimeException $e) {
                    $errors['city'] = $e->getMessage();
                }
            }
        } elseif ($mode === 'coords') {
            $lat = $in['lat'] ?? null;
            $lon = $in['lon'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                $errors['lat'] = 'Enter a valid latitude (-90..90) and longitude (-180..180).';
            } else {
                $km = max(0.5, min(50, (float) ($in['radius_km'] ?? 5)));
                $loc = ['mode' => 'coords', 'label' => sprintf('%.5f, %.5f (%s km)', $lat, $lon, $km), 'lat' => (float) $lat, 'lon' => (float) $lon, 'bbox' => GeoService::bboxAround((float) $lat, (float) $lon, $km)];
            }
        } else {
            $grid = 1;
        }

        $perSearch = max(1, min(120, (int) ($in['max_per_search'] ?? 60)));
        $maxTimeLimit = max(60, (int) setting('jobs.max_time_limit', 7200));
        $maxTime = max(60, min($maxTimeLimit, (int) ($in['max_time'] ?? setting('jobs.max_time_default', 900))));
        $tasks = count($keywords) * $grid * $grid;
        if ($tasks > 2000) {
            $errors['grid'] = "This job would run $tasks searches. Use fewer keywords or a smaller grid (max 2000).";
        }

        $exclude = [];
        foreach ((array) ($in['exclude_jobs'] ?? []) as $jid) {
            if (is_string($jid) && preg_match('/^[0-9a-f-]{36}$/', $jid)) {
                $exclude[] = $jid;
            }
        }
        $scope = App::auth()->scope();
        if ($exclude && $scope !== null) {
            $repo = new JobRepository(App::db());
            $exclude = array_values(array_filter($exclude, static fn ($j) => $repo->find($j, $scope) !== null));
        }

        $limit = (int) setting('security.job_create_per_hour', 30);
        if (!$errors && $limit > 0) {
            $rl = (new RateLimiter(App::db()))->hit('jobs:create:' . $userId, $limit, 3600);
            if (!$rl['allowed']) {
                $errors['name'] = "You can create $limit jobs per hour. Try again in " . ceil($rl['retry_after'] / 60) . ' min.';
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $flag = static fn (string $k, bool $def) => array_key_exists($k, $in) ? filter_var($in[$k], FILTER_VALIDATE_BOOL) : $def;
        $params = [
            'keywords' => $keywords,
            'lang' => $lang,
            'country' => $country,
            'location' => $loc,
            'grid' => $grid,
            'deep_scan' => $flag('deep_scan', false) && $loc['bbox'] !== null,
            'max_per_search' => $perSearch,
            'max_time' => $maxTime,
            'emails' => $flag('emails', true),
            'socials' => $flag('socials', true),
            'verify_emails' => $flag('verify_emails', false),
            'tech' => $flag('tech', false),
            'only_without_website' => $flag('only_without_website', false),
            'exclude_jobs' => $exclude,
        ];
        if ($params['only_without_website']) {
            $params['emails'] = $params['socials'] = $params['verify_emails'] = $params['tech'] = false;
        }
        $id = uuid4();
        (new JobRepository(App::db()))->create($id, $userId, $name, $provider, $params);
        Audit::log('job.created', 'job', $id, ['name' => $name, 'provider' => $provider, 'keywords' => count($keywords), 'grid' => $grid]);
        return ['ok' => true, 'id' => $id];
    }

    /** Public JSON shape of a job (API + UI polling). */
    public static function present(array $j): array
    {
        $p = $j['params'];
        return [
            'id' => $j['id'],
            'name' => $j['name'],
            'status' => $j['status'],
            'provider' => $j['provider'],
            'progress' => [
                'percent' => (int) ($j['progress']['percent'] ?? 0),
                'message' => (string) ($j['progress']['message'] ?? ''),
                'searches_done' => (int) ($j['progress']['cursor'] ?? 0),
                'searches_total' => (int) ($j['progress']['total'] ?? 0),
            ],
            'places_found' => (int) $j['places_found'],
            'emails_found' => (int) $j['emails_found'],
            'api_calls' => (int) $j['api_calls'],
            'error' => $j['error'],
            'warnings' => $j['warnings'] ? explode("\n", (string) $j['warnings']) : [],
            'params' => [
                'keywords' => $p['keywords'] ?? [], 'location' => $p['location']['label'] ?? '', 'grid' => $p['grid'] ?? 1,
                'deep_scan' => $p['deep_scan'] ?? false, 'max_per_search' => $p['max_per_search'] ?? 0, 'lang' => $p['lang'] ?? '',
                'country' => $p['country'] ?? '', 'emails' => $p['emails'] ?? false, 'socials' => $p['socials'] ?? false,
                'verify_emails' => $p['verify_emails'] ?? false, 'tech' => $p['tech'] ?? false,
                'only_without_website' => $p['only_without_website'] ?? false, 'max_time' => $p['max_time'] ?? 0,
            ],
            'created_at' => $j['created_at'],
            'started_at' => $j['started_at'],
            'finished_at' => $j['finished_at'],
        ];
    }
}
