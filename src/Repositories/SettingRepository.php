<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Crypto;
use App\Core\Database;
use Throwable;

final class SettingRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Non-secret overrides, decoded from JSON. */
    public function allPlain(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT `key`, `value` FROM {settings} WHERE is_secret = 0') as $r) {
            $out[$r['key']] = json_decode((string) $r['value'], true);
        }
        return $out;
    }

    public function set(string $key, mixed $value, bool $secret, ?int $userId): void
    {
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', $key)) {
            throw new \InvalidArgumentException('Invalid setting key');
        }
        if ($secret) {
            $stored = $value === '' ? '' : Crypto::encrypt((string) $value);
        } else {
            $stored = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->db->query(
            'INSERT INTO {settings} (`key`, `value`, is_secret, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$key, $stored, $secret ? 1 : 0, $userId, now_utc()]
        );
    }

    public function secret(string $key): string
    {
        $v = $this->db->value('SELECT `value` FROM {settings} WHERE `key` = ? AND is_secret = 1', [$key]);
        if (!$v) {
            return '';
        }
        try {
            return Crypto::decrypt((string) $v);
        } catch (Throwable) {
            return '';
        }
    }

    public function hasSecret(string $key): bool
    {
        return (bool) $this->db->value("SELECT 1 FROM {settings} WHERE `key` = ? AND is_secret = 1 AND `value` <> ''", [$key]);
    }
}
