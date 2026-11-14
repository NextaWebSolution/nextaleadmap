<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\SettingRepository;
use App\Services\AuthService;
use RuntimeException;
use Throwable;

/**
 * Application kernel and minimal service locator.
 * Services are created lazily so the installer can run before a database exists.
 */
final class App
{
    public static string $root = '';
    public static ?array $user = null;
    public static string $cspNonce = '';
    public static ?string $apiKeyId = null;

    private static ?Database $db = null;
    private static ?Settings $settings = null;
    private static ?AuthService $auth = null;
    private static ?string $basePath = null;
    private static ?Logger $logger = null;

    public static function boot(string $root): void
    {
        self::$root = rtrim($root, '/');
        Env::load(self::$root . '/.env');
        self::$cspNonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        error_reporting(E_ALL);
        ini_set('display_errors', Env::bool('APP_DEBUG') ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', self::$root . '/storage/logs/php-error.log');
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
                return false; // logged by PHP, never fatal (keeps PHP 8.4+ compatible)
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
    }

    public static function version(): string
    {
        static $v = null;
        return $v ??= trim((string) @file_get_contents(self::$root . '/VERSION')) ?: '0.0.0';
    }

    public static function installed(): bool
    {
        return is_file(self::$root . '/storage/installed.lock') && is_file(self::$root . '/.env');
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            if (!Env::get('DB_DATABASE')) {
                throw new RuntimeException('Database is not configured. Run the installer.');
            }
            self::$db = Database::connect([
                'host' => Env::get('DB_HOST', '127.0.0.1'),
                'port' => (int) Env::get('DB_PORT', '3306'),
                'database' => Env::get('DB_DATABASE'),
                'username' => Env::get('DB_USERNAME'),
                'password' => Env::get('DB_PASSWORD'),
                'prefix' => Env::get('DB_PREFIX', 'nlm_'),
                'ssl_ca' => Env::get('DB_SSL_CA'),
            ]);
        }
        return self::$db;
    }

    public static function setDb(Database $db): void
    {
        self::$db = $db;
    }

    public static function settings(): Settings
    {
        if (self::$settings === null) {
            $repo = null;
            try {
                if (self::installed()) {
                    $repo = new SettingRepository(self::db());
                }
            } catch (Throwable) {
                $repo = null;
            }
            self::$settings = new Settings(self::$root . '/config/app.json', $repo);
        }
        return self::$settings;
    }

    public static function auth(): AuthService
    {
        return self::$auth ??= new AuthService();
    }

    public static function logger(): Logger
    {
        return self::$logger ??= new Logger(self::$root . '/storage/logs');
    }

    /** URL path prefix when installed in a sub-directory, '' at the domain root. */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }
        $appUrl = Env::get('APP_URL');
        if ($appUrl) {
            $p = rtrim((string) parse_url($appUrl, PHP_URL_PATH), '/');
            return self::$basePath = $p;
        }
        // Before install: derive from the script location, hiding /public when rewritten from the root.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = rtrim(dirname($script), '/');
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_ends_with($dir, '/public') && !str_starts_with($uri, $dir)) {
            $dir = substr($dir, 0, -7);
        }
        return self::$basePath = $dir === '/' ? '' : $dir;
    }

    public static function maintenance(): ?array
    {
        $f = self::$root . '/storage/maintenance.json';
        if (!is_file($f)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : ['message' => 'Maintenance'];
    }
}
