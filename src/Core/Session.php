<?php

declare(strict_types=1);

namespace App\Core;

/** Hardened PHP session: strict mode, secure cookies, idle + absolute timeouts. */
final class Session
{
    public static function start(Request $r): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = App::$root . '/storage/sessions';
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (PHP_VERSION_ID < 80400) { // deprecated in 8.4, where 32 chars × 6 bits are the default
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
        ini_set('session.gc_maxlifetime', (string) (12 * 3600));
        session_name('nlm_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (App::basePath() ?: '') . '/',
            // Secure when the request is HTTPS, or when the public URL is HTTPS (TLS terminated by a proxy/CDN).
            'secure' => $r->isSecure() || str_starts_with(strtolower((string) Env::get('APP_URL', '')), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $idle = 60 * max(5, (int) setting('security.session_idle_minutes', 60));
        $abs = 3600 * max(1, (int) setting('security.session_absolute_hours', 12));
        $last = (int) ($_SESSION['_last'] ?? $now);
        $born = (int) ($_SESSION['_born'] ?? $now);
        if (isset($_SESSION['uid']) && ($now - $last > $idle || $now - $born > $abs)) {
            self::destroy();
            session_start();
            $_SESSION['_expired'] = true;
        }
        $_SESSION['_last'] = $now;
        $_SESSION['_born'] ??= $now;
    }

    /** Call after login / privilege change to prevent session fixation. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_born'] = time();
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function flashInput(array $input, array $errors = []): void
    {
        $safe = $input;
        foreach (array_keys($safe) as $k) {
            if (preg_match('/password|token|api_key|secret|proxies/i', (string) $k)) {
                unset($safe[$k]); // never keep secrets in the session store
            }
        }
        $_SESSION['_old'] = $safe;
        $_SESSION['_errors'] = $errors;
    }

    public static function clearInput(): void
    {
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }
}
