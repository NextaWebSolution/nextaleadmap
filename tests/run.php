<?php

declare(strict_types=1);

/*
 * Self-contained test suite (no PHPUnit, no database needed):  php tests/run.php
 * Covers the security-critical pure logic and renders every view with sample data.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Crypto;
use App\Core\Env;
use App\Core\HttpClient;
use App\Core\Validator;
use App\Core\View;
use App\Services\Enrichment\EmailExtractor;
use App\Services\Enrichment\SocialExtractor;
use App\Services\Enrichment\TechDetector;
use App\Services\Enrichment\WebsiteEnricher;
use App\Services\Export\Exporter;
use App\Services\GeoService;
use App\Services\Update\ManifestVerifier;
use App\Services\Update\SafeZip;
use App\Services\Update\Updater;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m ") . $name . "\n";
}
function section(string $s): void
{
    echo "\n\033[1m$s\033[0m\n";
}

section('SSRF guard');
foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::7f00:1', '224.0.0.1'] as $ip) {
    check("blocks $ip", !HttpClient::isPublicIp($ip));
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '142.250.190.14'] as $ip) {
    check("allows $ip", HttpClient::isPublicIp($ip));
}
$vetFails = static function (string $u): bool {
    try {
        HttpClient::vet($u);
        return false;
    } catch (RuntimeException) {
        return true;
    }
};
foreach (['http://127.0.0.1/', 'http://localhost/', 'file:///etc/passwd', 'gopher://x.com', 'http://user:pw@example.com/', 'http://example.com:22/', 'http://[::1]/', 'http://169.254.169.254/latest/meta-data', 'http://intranet/', 'http://0x7f000001/'] as $u) {
    check("vet rejects $u", $vetFails($u));
}

section('Crypto');
Env::load('/nonexistent');
$ref = new ReflectionClass(Env::class);
$prop = $ref->getProperty('vars');
$prop->setValue(null, ['APP_KEY' => Crypto::generateKey()]);
$c = Crypto::encrypt('sk-secret-123');
check('encrypt/decrypt round trip', Crypto::decrypt($c) === 'sk-secret-123');
check('ciphertext hides plaintext', !str_contains($c, 'secret'));
$tampered = substr($c, 0, -4) . 'AAAA';
try {
    Crypto::decrypt($tampered);
    check('tamper detected', false);
} catch (RuntimeException) {
    check('tamper detected', true);
}

section('Env');
$envText = Env::render(['APP_KEY' => 'base64:ab+/=', 'DB_PASSWORD' => 'p@ss "word" #1', 'X' => 'plain']);
$tmp = tempnam(sys_get_temp_dir(), 'env');
file_put_contents($tmp, $envText);
Env::load($tmp);
check('env quotes special chars', Env::get('DB_PASSWORD') === 'p@ss "word" #1');
check('env keeps base64 key', Env::get('APP_KEY') === 'base64:ab+/=');
unlink($tmp);
$prop->setValue(null, ['APP_KEY' => Crypto::generateKey()]);

section('Signed update manifests');
$kp = sodium_crypto_sign_keypair();
$pk = base64_encode(sodium_crypto_sign_publickey($kp));
$sk = base64_encode(sodium_crypto_sign_secretkey($kp));
$m = ['version' => '1.1.0', 'release_date' => '2026-11-14', 'minimum_php' => '8.3', 'archive' => 'release-package.zip', 'checksum' => str_repeat('a', 64), 'release_notes' => 'Fixes'];
$m['signature'] = ManifestVerifier::sign($m, $sk);
$v = new ManifestVerifier([$pk]);
check('valid signature accepted', $v->verify($m)['version'] === '1.1.0');
$bad = $m;
$bad['checksum'] = str_repeat('b', 64);
$rejects = static function (ManifestVerifier $v, array $m): bool {
    try {
        $v->verify($m);
        return false;
    } catch (RuntimeException) {
        return true;
    }
};
check('altered checksum rejected', $rejects($v, $bad));
$bad = $m;
$bad['archive'] = 'https://evil.example/x.zip';
check('altered archive rejected', $rejects($v, $bad));
$other = sodium_crypto_sign_keypair();
check('wrong key rejected', $rejects(new ManifestVerifier([base64_encode(sodium_crypto_sign_publickey($other))]), $m));
check('no key = updates disabled', $rejects(new ManifestVerifier([]), $m));
$reordered = array_reverse($m, true);
check('key order does not matter', $v->verify($reordered)['version'] === '1.1.0');

section('Safe ZIP extraction');
$dir = sys_get_temp_dir() . '/nlm-zip-' . bin2hex(random_bytes(3));
mkdir($dir);
$mk = static function (array $entries) use ($dir): string {
    $f = $dir . '/' . bin2hex(random_bytes(4)) . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $z->addFromString($name, $content);
    }
    $z->close();
    return $f;
};
$extractFails = static function (string $zip, string $dest) {
    try {
        (new SafeZip())->extract($zip, $dest, [Updater::class, 'allowedPath']);
        return false;
    } catch (RuntimeException) {
        return true;
    }
};
check('rejects ../ traversal', $extractFails($mk(['../evil.php' => 'x']), "$dir/o1"));
check('rejects absolute path', $extractFails($mk(['/etc/cron.d/x' => 'x']), "$dir/o2"));
check('rejects .env overwrite', $extractFails($mk(['.env' => 'APP_KEY=x', 'VERSION' => '1']), "$dir/o3"));
check('rejects storage/ writes', $extractFails($mk(['storage/x.php' => 'x', 'VERSION' => '1']), "$dir/o4"));
check('rejects unknown roots', $extractFails($mk(['shell.php' => 'x']), "$dir/o5"));
$okZip = $mk(['nextaleadmap/VERSION' => '1.1.0', 'nextaleadmap/src/X.php' => '<?php', 'nextaleadmap/public/index.php' => '<?php']);
$files = (new SafeZip())->extract($okZip, "$dir/o6", [Updater::class, 'allowedPath']);
check('strips single top folder', in_array('src/X.php', $files, true) && is_file("$dir/o6/VERSION"));
check('cleanPath normalises', SafeZip::cleanPath('a/./b//c.php') === 'a/b/c.php' && SafeZip::cleanPath('a\\b') === null);
// symlink entry
$f = $dir . '/link.zip';
$z = new ZipArchive();
$z->open($f, ZipArchive::CREATE);
$z->addFromString('src/link.php', '/etc/passwd');
$z->setExternalAttributesName('src/link.php', ZipArchive::OPSYS_UNIX, (0120777 << 16));
$z->close();
check('rejects symlinks', $extractFails($f, "$dir/o7"));
exec('rm -rf ' . escapeshellarg($dir));

section('Email / social / tech extraction (ported rules)');
$ex = new EmailExtractor();
$html = '<a href="mailto:Info%40Acme-Dental.com">Mail</a> sales@acme-dental.com noreply@acme-dental.com logo@2x.png user@example.com
         sentry@o123.ingest.sentry.io 0123456789abcdef0123@tracking.com u003eorders@acme-dental.com help@gmail.com office@city.gov';
$found = $ex->find($html, 'acme-dental.com');
check('finds real addresses', in_array('info@acme-dental.com', $found, true) && in_array('sales@acme-dental.com', $found, true));
check('drops junk', !array_intersect($found, ['noreply@acme-dental.com', 'logo@2x.png', 'user@example.com']));
check('max 3, own domain first', count($found) === 3 && str_ends_with($found[0], '@acme-dental.com'));
check('gov label is junk', !$ex->real('office@city.gov') && $ex->real('hello@government-plumbing.com'));
$soc = (new SocialExtractor())->find('<a href="https://www.facebook.com/sharer.php?u=x">s</a><a href="https://facebook.com/AcmeDental">f</a>
    <a href="https://instagram.com/acme.dental/">i</a><a href="https://www.instagram.com/wix">w</a><a href="https://x.com/intent/tweet">t</a><a href="https://twitter.com/acmedental">x</a>
    <a href="https://www.linkedin.com/company/acme-dental">l</a>');
check('facebook profile not share link', $soc['facebook'] === 'https://facebook.com/AcmeDental');
check('instagram', $soc['instagram'] === 'https://instagram.com/acme.dental');
check('x handle skips intent', $soc['x'] === 'https://twitter.com/acmedental');
check('linkedin company', $soc['linkedin'] === 'https://www.linkedin.com/company/acme-dental');
$tech = (new TechDetector())->detect('<script src="https://connect.facebook.net/en_US/fbevents.js"></script><meta name="viewport" content="x"><link href="/wp-content/x.css"> gtag("config","G-ABC1234")');
check('tech detection', $tech['fb_pixel'] === 'yes' && $tech['cms'] === 'wordpress' && $tech['analytics'] === 'yes' && $tech['mobile_friendly'] === 'yes');
check('intent score formula', TechDetector::intentScore($tech, ['review_count' => 60, 'rating' => 4.5], true) === 35 + 10 + 15 + 10 + 5);
$enr = new WebsiteEnricher(new HttpClient(), []);
$page = '<html><body>' . str_repeat('Welcome to Bright Smile family dentistry clinic. ', 10) . ' Call 020-555-1234</body></html>';
check('owns site by phone', $enr->ownsSite($page, ['phone' => '+91 20 5551234', 'category' => 'Bakery']));
check('owns site by category', $enr->ownsSite($page, ['phone' => '', 'category' => 'Dentist']));
check('not theirs', !$enr->ownsSite($page, ['phone' => '+1 999 888 7777', 'category' => 'Plumber']));
check('relative link resolution', WebsiteEnricher::absolute('contact.html', 'https://a.com/x/index.html') === 'https://a.com/x/contact.html' && WebsiteEnricher::absolute('/c', 'https://a.com/x') === 'https://a.com/c');
check('utm stripped', WebsiteEnricher::normalizeUrl('acme.com/?utm_source=gbp&p=2') === 'http://acme.com/?p=2');

section('CSV formula guard');
check('= guarded', Exporter::safe('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")");
check('@ guarded', Exporter::safe('@SUM(1)') === "'@SUM(1)");
check('phone kept', Exporter::safe('+1 512-555-0100') === '+1 512-555-0100');
check('phone with ext kept', Exporter::safe('+1 512-555-0100 ext. 12') === '+1 512-555-0100 ext. 12');
check('+cmd guarded', Exporter::safe('+cmd|calc') === "'+cmd|calc");
check('-negative formula guarded', Exporter::safe('-2+3+cmd') === "'-2+3+cmd");

section('Grid geometry (port of grid_cells)');
$cells = GeoService::gridCells([18.40, 18.64, 73.74, 73.98], 3);
check('3x3 = 9 cells', count($cells) === 9);
check('zoom within 11..17', min(array_column($cells, 'zoom')) >= 11 && max(array_column($cells, 'zoom')) <= 17);
check('radius >= 1000', min(array_column($cells, 'radius')) >= 1000);
check('split gives 4 deeper cells', count(GeoService::split($cells[0])) === 4 && GeoService::split($cells[0])[0]['level'] === 1);
check('inBbox margin', GeoService::inBbox(['latitude' => 18.39, 'longitude' => 73.8], [18.40, 18.64, 73.74, 73.98]) && !GeoService::inBbox(['latitude' => 19, 'longitude' => 73.8], [18.40, 18.64, 73.74, 73.98]));

section('Validation');
check('strong password', Validator::strongPassword('Abcdefgh12') && !Validator::strongPassword('abcdefgh12'));
$vv = Validator::make(['n' => '5', 's' => 'abc', 'u' => 'ftp://x'], ['n' => 'int|min:10', 's' => 'max:2', 'u' => 'https']);
check('numeric min / string max / https', isset($vv->errors['n'], $vv->errors['s'], $vv->errors['u']));
check('update allow-list', Updater::allowedPath('src/Core/App.php') && !Updater::allowedPath('.env') && !Updater::allowedPath('storage/logs/x') && !Updater::allowedPath('config/local.json') && !Updater::allowedPath('public/uploads/a.php'));

section('Audit regressions (2026-11-14)');
foreach (['/\\evil.com', '//evil.com', 'https://evil.com', "/ok\r\nSet-Cookie: x", '', 'evil.com'] as $bad) {
    check('redirect rejects ' . json_encode($bad), !\App\Core\Response::isLocalPath($bad));
}
check('redirect allows /jobs?page=2', \App\Core\Response::isLocalPath('/jobs?page=2'));
$loc = \App\Core\Response::redirect('/\\evil.com')->headers['Location'];
check('redirect falls back to app root', !str_contains($loc, 'evil'));
// anti-escalation: an analyst cannot grant or take over beyond their own permissions
\App\Core\App::auth()->setUser(['id' => 5, 'name' => 'M', 'email' => 'm@x', 'role_permissions' => '["users.manage","jobs.view"]']);
check('manager covers own perms', \App\Core\App::auth()->covers(['jobs.view']));
check('manager cannot grant settings.manage', !\App\Core\App::auth()->covers(['jobs.view', 'settings.manage']));
check('manager cannot grant *', !\App\Core\App::auth()->covers(['*']));
\App\Core\App::auth()->setUser(['id' => 1, 'name' => 'A', 'email' => 'a@x', 'role_permissions' => '["*"]']);
check('super admin covers *', \App\Core\App::auth()->covers(['*', 'system.manage']));
// startup validation
$prop->setValue(null, ['APP_KEY' => 'base64:short', 'APP_URL' => 'javascript:alert(1)']);
$errs = Env::configErrors();
check('config: bad APP_KEY / APP_URL / DB vars detected', count($errs) === 5 && !str_contains(implode(' ', $errs), 'short'));
$prop->setValue(null, ['APP_KEY' => Crypto::generateKey(), 'APP_URL' => 'https://leads.example.com', 'DB_HOST' => 'h', 'DB_DATABASE' => 'd', 'DB_USERNAME' => 'u']);
check('config: valid env passes', Env::configErrors() === []);
// header injection via User-Agent
$hc = new HttpClient([], "Bot\r\nX-Evil: 1");
$ua = (new ReflectionProperty(HttpClient::class, 'userAgent'))->getValue($hc);
check('user agent stripped of CR/LF', !str_contains($ua, "\n") && !str_contains($ua, "\r"));
check('settings UA rule rejects CRLF', Validator::make(['u' => "a\r\nb"], ['u' => 'regex:/^[\x20-\x7E]+$/'])->fails());
// CSP: CDN allowed only while libraries are not self-hosted
$csp = \App\Core\Response::securityHeaders(new \App\Core\Request('GET', '/', [], [], []))['Content-Security-Policy'];
check('CSP has nonce, frame-ancestors none, no unsafe-inline scripts', str_contains($csp, "'nonce-") && str_contains($csp, "frame-ancestors 'none'") && !preg_match("/script-src[^;]*'unsafe-inline'/", $csp));
check('vendor_script falls back to pinned CDN with crossorigin', str_contains(vendor_script('alpine'), 'alpinejs@3.14.9') && str_contains(vendor_script('alpine'), 'crossorigin'));
// secrets never kept in session old-input
$_SESSION = [];
\App\Core\Session::flashInput(['name' => 'x', 'providers__serpapi__api_key' => 'SECRET', 'confirm_password' => 'p', 'db_password' => 'p']);
check('secrets not stored in session', array_keys($_SESSION['_old']) === ['name']);
// job error text: internal exceptions never reach users
$src = file_get_contents(dirname(__DIR__) . '/src/Services/JobRunner.php');
check('job errors use reference ids for internal exceptions', str_contains($src, "'Internal error while running the job (reference '"));

section('Views render (sample data)');
App::auth()->setUser(['id' => 1, 'name' => 'Rupan Santra', 'email' => 'admin@example.com', 'role_name' => 'Administrator', 'role_slug' => 'admin', 'role_permissions' => '["*"]']);
$_SESSION = [];
$job = ['id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'name' => 'Dentists Pune', 'provider' => 'google_places', 'status' => 'running', 'user_name' => 'Rupan',
    'params' => ['keywords' => ['dentists', 'orthodontists'], 'location' => ['label' => 'Pune, Maharashtra, IN'], 'grid' => 2, 'emails' => true, 'socials' => true, 'tech' => true],
    'progress' => ['percent' => 42, 'message' => 'Searching "dentists" — tile 2/4'], 'places_found' => 120, 'emails_found' => 48, 'api_calls' => 9,
    'error' => null, 'warnings' => "Max time reached: 3 of 8 searches done.", 'created_at' => '2026-11-14 05:00:00', 'started_at' => '2026-11-14 05:00:05', 'finished_at' => null];
$lead = ['id' => 7, 'job_id' => $job['id'], 'job_name' => 'Dentists Pune', 'title' => 'Bright <Smile> Dental', 'category' => 'Dentist', 'address' => 'FC Road, Pune', 'phone' => '+91 20 5551234',
    'website' => 'brightsmile.example', 'emails' => 'info@brightsmile.example, care@brightsmile.example', 'rating' => '4.7', 'review_count' => 312, 'intent_score' => 70, 'lead_status' => 'new',
    'instagram' => 'https://instagram.com/brightsmile', 'facebook' => '', 'linkedin' => null, 'tiktok' => null, 'youtube' => null, 'x' => null, 'maps_url' => 'https://www.google.com/maps/search/?api=1&query=x',
    'created_at' => '2026-11-14 05:01:00', 'email_host' => 'google_workspace', 'plus_code' => '7JCMGFG7+2Q', 'latitude' => '18.52', 'longitude' => '73.85', 'price_level' => '$$', 'business_status' => 'operational',
    'website_status' => 'ok', 'opening_hours' => 'Monday: 9 AM–7 PM; Tuesday: 9 AM–7 PM', 'notes' => 'Call back <b>Monday</b>', 'keyword' => 'dentists', 'enriched_at' => '2026-11-14 05:02:00', 'tech' => '{}'];
$pg = ['total' => 120, 'page' => 2, 'per_page' => 25, 'pages' => 5];
$views = [
    'auth/login' => [],
    'dashboard/index' => ['totals' => ['total' => 1200, 'with_email' => 500, 'with_phone' => 1100, 'with_website' => 900, 'won' => 4, 'avg_rating' => 4.4], 'byStatus' => ['running' => 1, 'done' => 5],
        'recent' => [$job], 'chart' => ['labels' => ['01 Nov'], 'leads' => [5], 'emails' => [2], 'categories' => ['labels' => ['Dentist'], 'values' => [5]], 'statuses' => ['new' => 3, 'won' => 1]], 'providersReady' => false, 'workerSeen' => null],
    'jobs/index' => ['jobs' => [$job], 'filters' => ['status' => '', 'q' => ''], 'pg' => $pg],
    'jobs/create' => ['providers' => ['google_places' => 'Google Places API (New)'], 'providerMeta' => \App\Services\Providers\ProviderFactory::PROVIDERS, 'types' => ['Dentists', 'Plumbers'], 'previousJobs' => [['id' => $job['id'], 'name' => 'x', 'places_found' => 3]]],
    'jobs/show' => ['job' => $job, 'leads' => [$lead], 'pg' => $pg],
    'leads/index' => ['leads' => [$lead], 'filters' => ['q' => '', 'job' => '', 'status' => '', 'has_email' => 'yes', 'has_website' => '', 'has_phone' => '', 'min_rating' => '', 'min_reviews' => '', 'min_score' => '', 'sort' => 'newest'], 'jobs' => [['id' => $job['id'], 'name' => 'Dentists']], 'pg' => $pg],
    'leads/show' => ['lead' => $lead, 'tech' => ['fb_pixel' => 'yes', 'cms' => 'wordpress']],
    'settings/index' => ['tab' => 'providers', 'tabs' => \App\Controllers\SettingsController::schema(), 'def' => \App\Controllers\SettingsController::schema()['providers']],
    'users/index' => ['users' => [['id' => 2, 'name' => 'Asha', 'email' => 'a@x.com', 'role_name' => 'Analyst', 'status' => 'active', 'last_login_at' => null]], 'q' => '', 'pg' => $pg],
    'users/form' => ['user' => null, 'roles' => [['id' => 1, 'name' => 'Administrator']]],
    'roles/index' => ['roles' => [['id' => 1, 'name' => 'Admin', 'description' => 'All', 'permissions' => '["*"]', 'is_system' => 1, 'users_count' => 1]], 'defs' => []],
    'roles/form' => ['role' => null, 'defs' => ['jobs.view' => 'View jobs']],
    'apikeys/index' => ['keys' => [['id' => 1, 'name' => 'CRM', 'prefix' => 'abcdef123456', 'scopes' => ['jobs:read'], 'last_used_at' => null, 'expires_at' => null, 'revoked_at' => null, 'user_name' => 'R']], 'scopes' => \App\Repositories\ApiKeyRepository::SCOPES, 'newKey' => 'nlm_abc_xyz', 'all' => false],
    'audit/index' => ['rows' => [['created_at' => '2026-11-14 05:00:00', 'action' => 'auth.login_failed', 'entity_type' => 'user', 'entity_id' => '1', 'via' => 'web', 'user_name' => null, 'ip' => '1.2.3.4', 'meta' => '{"email":"x"}']], 'filters' => ['action' => '', 'user' => '', 'from' => '', 'to' => ''], 'users' => [], 'pg' => $pg],
    'profile/index' => [],
    'system/index' => ['checks' => [['HTTPS', false, 'Fix it'], ['Debug off', true, '']], 'info' => ['PHP' => PHP_VERSION], 'counts' => ['pending' => 1, 'running' => 0, 'places' => 10, 'users' => 2], 'backups' => [['name' => 'files-1.0.0-to-1.1.0-20261114.zip', 'size' => 1000, 'time' => '2026-11-14 05:00:00']],
        'history' => [['created_at' => '2026-11-14 05:00:00', 'from_version' => '1.0.0', 'to_version' => '1.1.0', 'status' => 'success', 'log' => 'ok', 'user_name' => 'R']], 'pendingMigrations' => [], 'cronCommand' => '* * * * * php bin/worker.php --once',
        'cronUrl' => 'https://x/cron/run?token=t', 'workerSeen' => null, 'lastCheck' => null, 'maintenance' => null],
    'errors/error' => ['code' => 404, 'message' => 'Nope'],
];
foreach ($views as $tpl => $data) {
    try {
        $html = View::render($tpl, $data + ['title' => 'T']);
        $xssSafe = !str_contains($html, 'Bright <Smile>') && !str_contains($html, '<b>Monday</b>');
        check("render $tpl", strlen($html) > 500 && $xssSafe);
    } catch (Throwable $e) {
        check("render $tpl — " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    }
}
foreach (['install/requirements' => ['checks' => \App\Services\Installer::requirements(), 'ok' => true, 'step' => 1], 'install/database' => ['step' => 2], 'install/app' => ['step' => 3, 'urlGuess' => 'https://x', 'timezones' => ['UTC']], 'install/done' => ['step' => 4, 'log' => ['ok'], 'appUrl' => 'https://x']] as $tpl => $data) {
    try {
        check("render $tpl", strlen(View::render($tpl, $data, 'layouts/install')) > 500);
    } catch (Throwable $e) {
        check("render $tpl — " . $e->getMessage(), false);
    }
}

echo "\n" . ($fail ? "\033[31m$fail failed\033[0m, " : '') . "\033[32m$pass passed\033[0m\n";
exit($fail ? 1 : 0);
