<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->first('SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.permissions AS role_permissions
            FROM {users} u JOIN {roles} r ON r.id = u.role_id WHERE u.id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->first('SELECT u.*, r.slug AS role_slug, r.permissions AS role_permissions
            FROM {users} u JOIN {roles} r ON r.id = u.role_id WHERE u.email = ?', [mb_strtolower(trim($email))]);
    }

    public function paginate(string $search, int $page, int $per): array
    {
        $where = '1=1';
        $p = [];
        if ($search !== '') {
            $where = '(u.name LIKE ? OR u.email LIKE ?)';
            $p = [Database::like($search), Database::like($search)];
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {users} u WHERE $where", $p);
        $rows = $this->db->all("SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at, r.name AS role_name, r.slug AS role_slug
            FROM {users} u JOIN {roles} r ON r.id = u.role_id WHERE $where ORDER BY u.id DESC LIMIT ? OFFSET ?", array_merge($p, [$per, ($page - 1) * $per]));
        return ['rows' => $rows, 'total' => $total];
    }

    public function create(array $d): int
    {
        return (int) $this->db->insert('users', [
            'name' => $d['name'],
            'email' => mb_strtolower(trim($d['email'])),
            'password_hash' => self::hash($d['password']),
            'role_id' => (int) $d['role_id'],
            'status' => $d['status'] ?? 'active',
            'password_changed_at' => now_utc(),
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
    }

    public function update(int $id, array $d): void
    {
        $data = ['updated_at' => now_utc()];
        foreach (['name', 'role_id', 'status'] as $k) {
            if (array_key_exists($k, $d)) {
                $data[$k] = $d[$k];
            }
        }
        if (isset($d['email'])) {
            $data['email'] = mb_strtolower(trim($d['email']));
        }
        if (!empty($d['password'])) {
            $data['password_hash'] = self::hash($d['password']);
            $data['password_changed_at'] = now_utc();
        }
        $this->db->update('users', $data, 'id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('users', 'id = ?', [$id]);
    }

    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $opts = $algo === PASSWORD_BCRYPT ? ['cost' => 12] : [];
        return password_hash($password, $algo, $opts);
    }

    public function recordLogin(int $id, string $ip): void
    {
        $this->db->update('users', ['last_login_at' => now_utc(), 'last_login_ip' => $ip, 'failed_logins' => 0, 'locked_until' => null], 'id = ?', [$id]);
    }

    public function recordFailure(int $id, int $max, int $lockMinutes): void
    {
        $this->db->query('UPDATE {users} SET failed_logins = failed_logins + 1,
            locked_until = IF(failed_logins + 1 >= ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), locked_until) WHERE id = ?', [$max, $lockMinutes, $id]);
    }

    public function rehash(int $id, string $password): void
    {
        $this->db->update('users', ['password_hash' => self::hash($password)], 'id = ?', [$id]);
    }

    public function countAdmins(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM {users} u JOIN {roles} r ON r.id = u.role_id WHERE r.slug = 'admin' AND u.status = 'active'");
    }
}
