<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Crypto;
use App\Core\Database;

/**
 * API keys look like  nlm_<12-char prefix>_<secret>. Only an HMAC of the full key is stored;
 * the plaintext is shown to the user once.
 */
final class ApiKeyRepository
{
    public const SCOPES = [
        'jobs:read' => 'Read jobs and download results',
        'jobs:write' => 'Create, cancel and delete jobs',
        'leads:read' => 'Search and read leads',
        'leads:write' => 'Update lead status and notes',
    ];

    public function __construct(private Database $db)
    {
    }

    /** @return array{id:int, key:string} */
    public function create(int $userId, string $name, array $scopes, ?string $expiresAt): array
    {
        $prefix = substr(bin2hex(random_bytes(8)), 0, 12);
        $key = 'nlm_' . $prefix . '_' . Crypto::randomToken(30);
        $id = (int) $this->db->insert('api_keys', [
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 100),
            'prefix' => $prefix,
            'key_hash' => Crypto::tokenHash($key),
            'scopes' => json_encode(array_values(array_intersect($scopes, array_keys(self::SCOPES)))),
            'expires_at' => $expiresAt,
            'created_at' => now_utc(),
        ]);
        return ['id' => $id, 'key' => $key];
    }

    /** Resolve a presented key to [key row + user]. Constant-time hash compare. */
    public function authenticate(string $presented): ?array
    {
        if (!preg_match('/^nlm_([0-9a-f]{12})_[A-Za-z0-9]{20,80}$/', $presented, $m)) {
            return null;
        }
        $row = $this->db->first('SELECT k.*, u.status AS user_status FROM {api_keys} k JOIN {users} u ON u.id = k.user_id
            WHERE k.prefix = ? AND k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > UTC_TIMESTAMP())', [$m[1]]);
        if (!$row || $row['user_status'] !== 'active' || !hash_equals($row['key_hash'], Crypto::tokenHash($presented))) {
            return null;
        }
        $row['scopes'] = json_decode((string) $row['scopes'], true) ?: [];
        return $row;
    }

    public function touch(int $id, string $ip): void
    {
        $this->db->update('api_keys', ['last_used_at' => now_utc(), 'last_used_ip' => $ip], 'id = ?', [$id]);
    }

    public function forUser(?int $userId): array
    {
        $sql = 'SELECT k.*, u.name AS user_name FROM {api_keys} k JOIN {users} u ON u.id = k.user_id' . ($userId !== null ? ' WHERE k.user_id = ?' : '') . ' ORDER BY k.created_at DESC';
        return array_map(static function ($r) {
            $r['scopes'] = json_decode((string) $r['scopes'], true) ?: [];
            return $r;
        }, $this->db->all($sql, $userId !== null ? [$userId] : []));
    }

    public function revoke(int $id, ?int $userId): bool
    {
        $sql = 'UPDATE {api_keys} SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL' . ($userId !== null ? ' AND user_id = ?' : '');
        return $this->db->query($sql, $userId !== null ? [$id, $userId] : [$id])->rowCount() > 0;
    }
}
