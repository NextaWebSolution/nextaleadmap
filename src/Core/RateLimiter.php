<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiter stored in MySQL, so it works on shared hosting without Redis.
 * Keys are hashed; no raw IPs or emails are stored in the table.
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{allowed:bool, remaining:int, retry_after:int, limit:int} */
    public function hit(string $key, int $max, int $windowSeconds): array
    {
        $k = hash('sha256', $key);
        $this->db->query(
            'INSERT INTO {rate_limits} (bucket, hits, reset_at) VALUES (?, 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE
                hits = IF(reset_at <= UTC_TIMESTAMP(), 1, hits + 1),
                reset_at = IF(reset_at <= UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), reset_at)',
            [$k, $windowSeconds, $windowSeconds]
        );
        $row = $this->db->first('SELECT hits, GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), reset_at)) AS ttl FROM {rate_limits} WHERE bucket = ?', [$k]);
        $hits = (int) ($row['hits'] ?? 1);
        if (random_int(1, 200) === 1) {
            $this->db->query('DELETE FROM {rate_limits} WHERE reset_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
        }
        return ['allowed' => $hits <= $max, 'remaining' => max(0, $max - $hits), 'retry_after' => (int) ($row['ttl'] ?? $windowSeconds), 'limit' => $max];
    }

    public function tooMany(string $key, int $max): bool
    {
        $row = $this->db->first('SELECT hits FROM {rate_limits} WHERE bucket = ? AND reset_at > UTC_TIMESTAMP()', [hash('sha256', $key)]);
        return $row !== null && (int) $row['hits'] >= $max;
    }

    public function clear(string $key): void
    {
        $this->db->query('DELETE FROM {rate_limits} WHERE bucket = ?', [hash('sha256', $key)]);
    }
}
