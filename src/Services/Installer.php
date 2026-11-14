<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use App\Repositories\CityRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use PDOException;
use RuntimeException;

final class Installer
{
    public static function requirements(): array
    {
        $root = App::$root;
        $ext = static fn (string $e) => extension_loaded($e);
        $checks = [
            ['PHP 8.3 or newer', version_compare(PHP_VERSION, '8.3.0', '>='), 'You have ' . PHP_VERSION, true],
            ['PDO MySQL extension', $ext('pdo_mysql'), 'pdo_mysql', true],
            ['cURL extension', $ext('curl'), 'curl', true],
            ['mbstring extension', $ext('mbstring'), 'mbstring', true],
            ['OpenSSL extension', $ext('openssl'), 'openssl', true],
            ['Sodium extension', $ext('sodium'), 'sodium (encryption + signed updates)', true],
            ['Zip extension', $ext('zip'), 'zip (updates, backups)', true],
            ['Zlib extension', $ext('zlib'), 'zlib (presets, DB backups)', true],
            ['JSON extension', $ext('json'), 'json', true],
            ['intl extension', $ext('intl'), 'Recommended for accent-insensitive city search', false],
            ['Argon2id password hashing', defined('PASSWORD_ARGON2ID'), 'Falls back to bcrypt if missing', false],
        ];
        foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/cache', 'storage/exports', 'storage/backups', 'storage/updates', 'storage/tmp'] as $d) {
            $checks[] = ["Writable: $d/", is_dir("$root/$d") && is_writable("$root/$d"), 'chmod 755 (or 775) this folder', true];
        }
        $envOk = is_file("$root/.env") ? is_readable("$root/.env") : is_writable($root);
        $checks[] = ['.env can be created', $envOk, is_file("$root/.env") ? '.env exists and will be used as-is' : 'Application root must be writable once, or upload .env yourself', true];
        return array_map(static fn ($c) => ['label' => $c[0], 'ok' => $c[1], 'hint' => $c[2], 'required' => $c[3]], $checks);
    }

    public static function requirementsMet(): bool
    {
        foreach (self::requirements() as $r) {
            if ($r['required'] && !$r['ok']) {
                return false;
            }
        }
        return true;
    }

    public static function testDb(array $c): Database
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,255}$/', (string) $c['host']) || !preg_match('/^[A-Za-z0-9_$]{1,64}$/', (string) $c['database']) || !preg_match('/^[a-z0-9_]{0,20}$/i', (string) $c['prefix'])) {
            throw new RuntimeException('Check the host, database name (letters, numbers, _) and table prefix.');
        }
        try {
            $db = Database::connect($c);
            $ver = (string) $db->value('SELECT VERSION()');
        } catch (PDOException $e) {
            throw new RuntimeException('Could not connect: ' . preg_replace('/\(using password: \w+\)/', '', $e->getMessage()));
        }
        $isMaria = stripos($ver, 'mariadb') !== false;
        $num = preg_replace('/[^0-9.].*$/', '', $ver);
        if ((!$isMaria && version_compare($num, '8.0.0', '<')) || ($isMaria && version_compare($num, '10.6.0', '<'))) {
            throw new RuntimeException("Database server $ver is too old. Need MySQL 8.0+ or MariaDB 10.6+.");
        }
        return $db;
    }

    /**
     * Writes .env (only if missing), migrates, seeds roles, creates the admin, imports presets, locks the installer.
     */
    public static function run(array $db, array $app, array $admin): array
    {
        $root = App::$root;
        $envFile = "$root/.env";
        $log = [];
        if (!is_file($envFile)) {
            $env = [
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => rtrim($app['url'], '/'),
                'APP_KEY' => Crypto::generateKey(),
                'DB_HOST' => $db['host'],
                'DB_PORT' => (string) $db['port'],
                'DB_DATABASE' => $db['database'],
                'DB_USERNAME' => $db['username'],
                'DB_PASSWORD' => $db['password'],
                'DB_PREFIX' => $db['prefix'],
                'TRUST_PROXY' => '0',
                'INSTALL_TOKEN' => '',
            ];
            if (file_put_contents($envFile, Env::render($env), LOCK_EX) === false) {
                throw new RuntimeException('Could not write .env. Make the application folder writable or upload .env manually.');
            }
            @chmod($envFile, 0600);
            $log[] = '.env created (permissions 0600)';
        } else {
            $log[] = '.env already exists — left untouched';
        }
        Env::load($envFile);
        if (!Env::get('APP_KEY')) {
            throw new RuntimeException('APP_KEY is empty in the existing .env. Add one: APP_KEY=' . Crypto::generateKey());
        }

        $conn = self::testDb([
            'host' => Env::get('DB_HOST'), 'port' => (int) Env::get('DB_PORT', '3306'), 'database' => Env::get('DB_DATABASE'),
            'username' => Env::get('DB_USERNAME'), 'password' => Env::get('DB_PASSWORD'), 'prefix' => Env::get('DB_PREFIX', ''),
        ]);
        App::setDb($conn);

        // Re-opened installer guard: if this database already holds an installation (for example the lock
        // file was deleted by a re-upload), never create another administrator or touch settings — re-lock instead.
        $usersTable = $conn->prefix . 'users';
        $exists = (int) $conn->value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$usersTable]) > 0;
        if ($exists && (int) $conn->value('SELECT COUNT(*) FROM ' . $conn->table('users')) > 0) {
            file_put_contents("$root/storage/installed.lock", json_encode(['relocked_at' => gmdate('c'), 'version' => App::version()]), LOCK_EX);
            App::logger()->warning('installer re-opened on an existing installation; re-locked without changes');
            throw new RuntimeException('This database already contains an installation. The installer has been locked again; sign in with your existing account (reset a password with "php bin/console.php user:password").');
        }

        $res = (new Migrator($conn, "$root/database/migrations"))->migrate();
        $log[] = 'Migrations: ' . (count($res['ran']) ?: 'none needed');

        $roles = new RoleRepository($conn);
        $roles->seedDefaults();
        $adminRole = $roles->findBySlug('admin');
        $users = new UserRepository($conn);
        if ($users->findByEmail($admin['email'])) {
            $log[] = 'Admin user already exists — kept';
        } else {
            $users->create(['name' => $admin['name'], 'email' => $admin['email'], 'password' => $admin['password'], 'role_id' => (int) $adminRole['id']]);
            $log[] = 'Administrator account created';
        }

        $settings = new \App\Repositories\SettingRepository($conn);
        $settings->set('app.name', $app['name'], false, null);
        $settings->set('app.timezone', $app['timezone'], false, null);
        $settings->set('cron.token', Crypto::randomToken(32), true, null);

        $cities = new CityRepository($conn);
        if ($cities->count() === 0) {
            @set_time_limit(300);
            $n = $cities->import("$root/database/presets/cities.tsv.gz");
            $log[] = number_format($n) . ' cities imported (GeoNames, CC BY 4.0)';
        }

        file_put_contents("$root/storage/installed.lock", json_encode(['installed_at' => gmdate('c'), 'version' => App::version()]), LOCK_EX);
        $log[] = 'Installer locked (storage/installed.lock)';
        return $log;
    }
}
