<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Services\Audit;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderFactory;

/**
 * Schema-driven settings. Every field is validated against its rule; secrets are encrypted at rest,
 * never echoed back, and left unchanged when the input is blank.
 */
final class SettingsController extends Controller
{
    public static function schema(): array
    {
        $tz = timezone_identifiers_list();
        return [
            'general' => ['label' => 'General', 'icon' => 'settings', 'perm' => 'settings.manage', 'fields' => [
                ['app.name', 'Application name', 'text', 'required|max:60'],
                ['app.company', 'Company name', 'text', 'max:100'],
                ['app.timezone', 'Display timezone', 'select', 'required', array_combine($tz, $tz)],
                ['app.per_page', 'Rows per page', 'number', 'required|int|min:5|max:200'],
                ['app.default_lang', 'Default search language', 'text', 'required|regex:/^[a-z]{2}(-[a-z]{2})?$/', null, 'Two-letter code, e.g. en, hi, de'],
                ['app.default_country', 'Default country', 'text', 'regex:/^[a-z]{2}$/', null, 'Two-letter code, e.g. in, us'],
            ]],
            'providers' => ['label' => 'Data providers', 'icon' => 'server', 'perm' => 'providers.manage', 'fields' => [
                ['providers.default', 'Default provider', 'select', 'required', array_map(static fn ($m) => $m[1], ProviderFactory::PROVIDERS)],
                ['providers.google_places.enabled', 'Enable Google Places API', 'toggle', ''],
                ['providers.google_places.api_key', 'Google Places API key', 'secret', 'max:200', null, 'Google Cloud key with "Places API (New)" enabled. Restrict it to your server IP.'],
                ['providers.serpapi.enabled', 'Enable SerpApi', 'toggle', ''],
                ['providers.serpapi.api_key', 'SerpApi API key', 'secret', 'max:200', null, 'From serpapi.com → Account → API key.'],
                ['providers.timeout', 'Request timeout (seconds)', 'number', 'required|int|min:5|max:120'],
                ['providers.max_retries', 'Retries on 429 / 5xx', 'number', 'required|int|min:0|max:6'],
                ['providers.use_proxies', 'Route provider calls through proxies', 'toggle', ''],
                ['providers.proxies', 'Proxy list (one per line)', 'secret_text', 'max:20000', null, 'http(s)://user:pass@host:port or socks5://host:port. Rotated per request.'],
            ]],
            'enrichment' => ['label' => 'Website enrichment', 'icon' => 'globe', 'perm' => 'settings.manage', 'fields' => [
                ['enrichment.workers', 'Parallel website fetches', 'number', 'required|int|min:1|max:32'],
                ['enrichment.timeout', 'Per-read timeout (s)', 'number', 'required|int|min:3|max:60'],
                ['enrichment.hard_timeout', 'Hard timeout per page (s)', 'number', 'required|int|min:5|max:120'],
                ['enrichment.max_bytes', 'Max bytes read per page', 'number', 'required|int|min:50000|max:3000000'],
                ['enrichment.contact_pages', 'Contact/about pages to follow', 'number', 'required|int|min:0|max:5'],
                ['enrichment.user_agent', 'User-Agent', 'text', 'required|max:255|regex:/^[\x20-\x7E]+$/'],
                ['enrichment.use_proxies', 'Fetch websites through proxies', 'toggle', ''],
                ['enrichment.extra_junk_domains', 'Extra email domains to ignore', 'list', 'max:5000', null, 'One per line, e.g. agency.com'],
            ]],
            'geocoding' => ['label' => 'Geocoding', 'icon' => 'map-pin', 'perm' => 'settings.manage', 'fields' => [
                ['geocoding.provider', 'Geocoder', 'select', 'required|in:nominatim,google,local', ['nominatim' => 'OpenStreetMap Nominatim', 'google' => 'Google Geocoding API', 'local' => 'Built-in city list only']],
                ['geocoding.nominatim_url', 'Nominatim URL', 'text', 'required|https|max:255'],
                ['geocoding.contact_email', 'Contact email (Nominatim policy)', 'text', 'email|max:190'],
                ['geocoding.google_api_key', 'Google Geocoding API key', 'secret', 'max:200'],
                ['geocoding.city_radius_km', 'City radius for built-in list (km)', 'number', 'required|numeric|min:1|max:100'],
            ]],
            'jobs' => ['label' => 'Jobs & worker', 'icon' => 'briefcase', 'perm' => 'settings.manage', 'fields' => [
                ['jobs.max_keywords', 'Max keywords per job', 'number', 'required|int|min:1|max:500'],
                ['jobs.max_time_default', 'Default max time (s)', 'number', 'required|int|min:60|max:86400'],
                ['jobs.max_time_limit', 'Max time limit (s)', 'number', 'required|int|min:60|max:86400'],
                ['jobs.grid_max', 'Largest grid (N×N)', 'number', 'required|int|min:1|max:10'],
                ['jobs.worker_max_runtime', 'Cron worker runtime (s)', 'number', 'required|int|min:20|max:3600', null, 'Keep below your cron interval (55 for every minute).'],
                ['jobs.stale_minutes', 'Resume jobs silent for (min)', 'number', 'required|int|min:2|max:120'],
                ['jobs.max_attempts', 'Max resume attempts', 'number', 'required|int|min:1|max:10'],
            ]],
            'security' => ['label' => 'Security', 'icon' => 'shield', 'perm' => 'settings.manage', 'fields' => [
                ['security.force_https', 'Force HTTPS', 'toggle', ''],
                ['security.session_idle_minutes', 'Idle session timeout (min)', 'number', 'required|int|min:5|max:1440'],
                ['security.session_absolute_hours', 'Absolute session lifetime (h)', 'number', 'required|int|min:1|max:168'],
                ['security.login_max_attempts', 'Failed logins before lockout', 'number', 'required|int|min:3|max:20'],
                ['security.login_window_minutes', 'Lockout window (min)', 'number', 'required|int|min:1|max:1440'],
                ['security.password_min_length', 'Minimum password length', 'number', 'required|int|min:8|max:64'],
                ['security.web_rate_limit_per_minute', 'Web requests / min / IP', 'number', 'required|int|min:30|max:10000'],
                ['security.api_rate_limit_per_minute', 'API requests / min / key', 'number', 'required|int|min:1|max:10000'],
                ['security.job_create_per_hour', 'Jobs per user per hour', 'number', 'required|int|min:0|max:1000'],
                ['security.audit_retention_days', 'Keep audit log (days, 0 = forever)', 'number', 'required|int|min:0|max:3650'],
            ]],
            'updates' => ['label' => 'Updates & backups', 'icon' => 'cloud-download', 'perm' => 'system.manage', 'fields' => [
                ['updates.server_url', 'Update manifest URL', 'text', 'https|max:500', null, 'HTTPS URL of manifest.json on your trusted release server.'],
                ['updates.auto_check', 'Check for updates daily', 'toggle', ''],
                ['updates.max_package_mb', 'Max package size (MB)', 'number', 'required|int|min:5|max:1000'],
                ['updates.db_backup', 'Back up the database before updating', 'toggle', ''],
                ['backup.keep', 'Backups to keep', 'number', 'required|int|min:1|max:50'],
            ]],
        ];
    }

    private function tabFor(string $tab): ?array
    {
        $s = self::schema();
        return isset($s[$tab]) && App::auth()->can($s[$tab]['perm']) ? $s[$tab] : null;
    }

    public function index(Request $r): Response
    {
        foreach (self::schema() as $k => $t) {
            if (App::auth()->can($t['perm'])) {
                return Response::redirect(url('settings/' . $k));
            }
        }
        return View::error(403, 'No settings available for your role.');
    }

    public function show(Request $r, string $tab): Response
    {
        $def = $this->tabFor($tab);
        if (!$def) {
            return View::error(404, 'Settings page not found');
        }
        $tabs = array_filter(self::schema(), static fn ($t) => App::auth()->can($t['perm']));
        return $this->view('settings/index', ['title' => 'Settings · ' . $def['label'], 'tab' => $tab, 'tabs' => $tabs, 'def' => $def]);
    }

    public function save(Request $r, string $tab): Response
    {
        $def = $this->tabFor($tab);
        if (!$def) {
            return View::error(403, 'Not allowed');
        }
        $data = [];
        $rules = [];
        $in = (array) $r->input();
        foreach ($def['fields'] as $f) {
            [$key, , $type, $rule] = $f;
            $field = str_replace('.', '__', $key);
            if (in_array($type, ['secret', 'secret_text'], true)) {
                continue;
            }
            $v = $type === 'toggle' ? !empty($in[$field]) : (is_scalar($in[$field] ?? null) ? trim((string) $in[$field]) : '');
            $data[$field] = $v;
            if ($rule !== '' && $type !== 'toggle') {
                $rules[$field] = $rule;
            }
            if ($type === 'select' && isset($f[4]) && !array_key_exists((string) $v, $f[4])) {
                return $this->failBack($r, [$field => $f[1] . ' is not a valid option.']);
            }
        }
        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            return $this->failBack($r, $v->errors);
        }
        $changed = [];
        foreach ($def['fields'] as $f) {
            [$key, , $type] = $f;
            $field = str_replace('.', '__', $key);
            if (in_array($type, ['secret', 'secret_text'], true)) {
                $val = is_string($in[$field] ?? null) ? trim($in[$field]) : '';
                if (!empty($in[$field . '__clear'])) {
                    App::settings()->setSecret($key, '', $this->userId());
                    $changed[] = $key . ' (cleared)';
                } elseif ($val !== '') {
                    App::settings()->setSecret($key, $val, $this->userId());
                    $changed[] = $key . ' (updated)';
                }
                continue;
            }
            $value = match ($type) {
                'toggle' => (bool) $data[$field],
                'number' => str_contains((string) $data[$field], '.') ? (float) $data[$field] : (int) $data[$field],
                'list' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $data[$field]) ?: []))),
                default => (string) $data[$field],
            };
            if (setting($key) !== $value) {
                App::settings()->set($key, $value, $this->userId());
                $changed[] = $key;
            }
        }
        Audit::log('settings.updated', 'settings', $tab, ['changed' => $changed]);
        flash('success', $changed ? 'Settings saved.' : 'Nothing changed.');
        return Response::redirect(url('settings/' . $tab));
    }

    public function testProvider(Request $r, string $id): Response
    {
        if ($resp = $this->throttle($r, 'provider_test', 10, 600)) {
            return $resp;
        }
        if (!isset(ProviderFactory::PROVIDERS[$id])) {
            return Response::error('Unknown provider', 404);
        }
        try {
            $res = ProviderFactory::make($id)->test();
        } catch (ProviderException $e) {
            $res = ['ok' => false, 'message' => $e->getMessage()];
        }
        Audit::log('provider.tested', 'provider', $id, ['ok' => $res['ok']]);
        return Response::json($res);
    }

    public function export(Request $r): Response
    {
        $data = ['app' => 'NextaLeadMap', 'version' => App::version(), 'exported_at' => gmdate('c'), 'settings' => []];
        $known = [];
        foreach (self::schema() as $t) {
            foreach ($t['fields'] as $f) {
                if (!in_array($f[2], ['secret', 'secret_text'], true)) {
                    $known[] = $f[0];
                }
            }
        }
        foreach ($known as $k) {
            $data['settings'][$k] = setting($k);
        }
        Audit::log('settings.exported');
        return Response::download('nextaleadmap-settings-' . gmdate('Ymd') . '.json', 'application/json', static function ($out) use ($data) {
            fwrite($out, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }

    public function import(Request $r): Response
    {
        $f = $r->files['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            flash('error', 'Choose a settings JSON file to import.');
            return Response::back($r, 'settings/general');
        }
        if ((int) $f['size'] > 262144 || !preg_match('/\.json$/i', (string) $f['name'])) {
            flash('error', 'The file must be a .json file under 256 KB.');
            return Response::back($r, 'settings/general');
        }
        if (function_exists('finfo_open')) {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']);
            if (!in_array($mime, ['application/json', 'text/plain', 'text/x-json'], true)) {
                flash('error', 'That file is not JSON.');
                return Response::back($r, 'settings/general');
            }
        }
        $json = json_decode((string) file_get_contents((string) $f['tmp_name']), true, 8);
        if (!is_array($json) || !is_array($json['settings'] ?? null)) {
            flash('error', 'Not a NextaLeadMap settings export.');
            return Response::back($r, 'settings/general');
        }
        $applied = 0;
        $skipped = [];
        foreach (self::schema() as $t) {
            if (!App::auth()->can($t['perm'])) {
                continue;
            }
            foreach ($t['fields'] as $fd) {
                [$key, , $type, $rule] = $fd;
                if (in_array($type, ['secret', 'secret_text'], true) || !array_key_exists($key, $json['settings'])) {
                    continue;
                }
                $val = $json['settings'][$key];
                $ok = match ($type) {
                    'toggle' => is_bool($val),
                    'list' => is_array($val) && count(array_filter($val, 'is_string')) === count($val),
                    'select' => is_scalar($val) && isset($fd[4]) && array_key_exists((string) $val, $fd[4]),
                    default => is_scalar($val) && ($rule === '' || !Validator::make(['v' => (string) $val], ['v' => $rule])->fails()),
                };
                if (!$ok) {
                    $skipped[] = $key;
                    continue;
                }
                App::settings()->set($key, $type === 'number' ? (is_float($val) ? (float) $val : (int) $val) : $val, $this->userId());
                $applied++;
            }
        }
        Audit::log('settings.imported', null, null, ['applied' => $applied, 'skipped' => $skipped]);
        flash($skipped ? 'warning' : 'success', "$applied setting(s) imported." . ($skipped ? ' Skipped invalid: ' . implode(', ', $skipped) : ''));
        return Response::redirect(url('settings/general'));
    }
}
