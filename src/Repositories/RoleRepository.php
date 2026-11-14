<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RoleRepository
{
    public function __construct(private Database $db)
    {
    }

    public function all(): array
    {
        return $this->db->all('SELECT r.*, (SELECT COUNT(*) FROM {users} u WHERE u.role_id = r.id) AS users_count FROM {roles} r ORDER BY r.id');
    }

    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {roles} WHERE id = ?', [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM {roles} WHERE slug = ?', [$slug]);
    }

    public function save(?int $id, string $slug, string $name, string $description, array $permissions, bool $system = false): int
    {
        $perm = json_encode(array_values(array_unique($permissions)));
        if ($id) {
            $this->db->update('roles', ['name' => $name, 'description' => $description, 'permissions' => $perm, 'updated_at' => now_utc()], 'id = ?', [$id]);
            return $id;
        }
        return (int) $this->db->insert('roles', [
            'slug' => $slug, 'name' => $name, 'description' => $description, 'permissions' => $perm,
            'is_system' => $system ? 1 : 0, 'created_at' => now_utc(), 'updated_at' => now_utc(),
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('roles', 'id = ? AND is_system = 0', [$id]);
    }

    public static function definitions(): array
    {
        return json_decode((string) file_get_contents(base_path('config/permissions.json')), true) ?: ['permissions' => [], 'roles' => []];
    }

    public function seedDefaults(): void
    {
        foreach (self::definitions()['roles'] as $slug => $r) {
            if (!$this->findBySlug($slug)) {
                $this->save(null, $slug, $r['name'], $r['description'], $r['permissions'], true);
            }
        }
    }
}
