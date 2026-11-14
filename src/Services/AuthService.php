<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\UserRepository;

final class AuthService
{
    private array $permissions = [];

    public function loadFromSession(): ?array
    {
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0) {
            return null;
        }
        $user = (new UserRepository(App::db()))->find($uid);
        // Invalidate sessions of disabled users and sessions older than a password change.
        if (!$user || $user['status'] !== 'active' || ($user['password_changed_at'] ?? '') !== ($_SESSION['pwd_at'] ?? '')) {
            Session::destroy();
            return null;
        }
        $this->setUser($user);
        return $user;
    }

    public function setUser(array $user): void
    {
        unset($user['password_hash']);
        App::$user = $user;
        $this->permissions = json_decode((string) ($user['role_permissions'] ?? '[]'), true) ?: [];
    }

    public function can(string $permission): bool
    {
        return App::$user !== null && (in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true));
    }

    public function isSuperAdmin(): bool
    {
        return App::$user !== null && in_array('*', $this->permissions, true);
    }

    /**
     * True when the current user already holds every permission in $perms.
     * Used to stop users.manage / roles.manage holders from granting (or taking over accounts with)
     * more power than they have themselves.
     */
    public function covers(array $perms): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        foreach ($perms as $p) {
            if ($p === '*' || !in_array($p, $this->permissions, true)) {
                return false;
            }
        }
        return true;
    }

    /** null = may see everything, otherwise the user id that data must belong to. */
    public function scope(): ?int
    {
        return $this->can('jobs.view_all') ? null : (int) (App::$user['id'] ?? 0);
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    public function attempt(Request $r, string $email, string $password): array
    {
        $limiter = new RateLimiter(App::db());
        $max = max(3, (int) setting('security.login_max_attempts', 5));
        $window = 60 * max(1, (int) setting('security.login_window_minutes', 15));
        $email = mb_strtolower(trim($email));
        $ipKey = 'login:ip:' . $r->ip();
        $mailKey = 'login:email:' . $email;

        if ($limiter->tooMany($ipKey, $max * 4) || $limiter->tooMany($mailKey, $max)) {
            Audit::log('auth.login_throttled', 'user', null, ['email' => $email]);
            return ['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.'];
        }
        $repo = new UserRepository(App::db());
        $user = $repo->findByEmail($email);
        // Verify against a dummy hash when the user is unknown to keep timing uniform.
        $hash = $user['password_hash'] ?? '$2y$12$6TCs4CglJySny5h7RmaZNOxvlsdpzMG4/i7LyaEXxZsM2MgMaV6P2';
        $valid = password_verify($password, $hash);

        if (!$user || !$valid || $user['status'] !== 'active') {
            $limiter->hit($ipKey, $max * 4, $window);
            $limiter->hit($mailKey, $max, $window);
            if ($user) {
                $repo->recordFailure((int) $user['id'], $max, (int) ($window / 60));
            }
            Audit::log('auth.login_failed', 'user', $user['id'] ?? null, ['email' => $email]);
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }
        // Lock state is only revealed to someone who already knows the password (no account enumeration).
        if ($user['locked_until'] && strtotime($user['locked_until'] . ' UTC') > time()) {
            Audit::log('auth.login_locked', 'user', $user['id']);
            return ['ok' => false, 'error' => 'This account is temporarily locked after repeated failures. Try again later.'];
        }
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $repo->rehash((int) $user['id'], $password);
        }
        Session::regenerate();
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['pwd_at'] = $user['password_changed_at'] ?? '';
        $limiter->clear($mailKey);
        $repo->recordLogin((int) $user['id'], $r->ip());
        $this->setUser($user);
        Audit::log('auth.login', 'user', $user['id']);
        return ['ok' => true];
    }

    /**
     * Re-authentication for sensitive actions (password change, updates, restores).
     * Limited to 5 attempts per 15 minutes per account so a hijacked session cannot brute-force the password.
     */
    public function verifyCurrentPassword(string $password, string $context): bool
    {
        if (App::$user === null) {
            return false;
        }
        $limiter = new RateLimiter(App::db());
        $key = 'reauth:' . App::$user['id'];
        if ($limiter->tooMany($key, 5)) {
            Audit::log('security.reauth_throttled', 'user', App::$user['id'], ['context' => $context]);
            return false;
        }
        $u = (new UserRepository(App::db()))->find((int) App::$user['id']);
        $ok = $u !== null && strlen($password) <= 1024 && password_verify($password, (string) $u['password_hash']);
        if (!$ok) {
            $limiter->hit($key, 5, 900);
            Audit::log('security.reauth_failed', 'user', App::$user['id'], ['context' => $context]);
        }
        return $ok;
    }

    public function logout(): void
    {
        if (App::$user) {
            Audit::log('auth.logout', 'user', App::$user['id']);
        }
        Session::destroy();
        App::$user = null;
    }
}
