<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class JobRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(string $id, ?int $userId, string $name, string $provider, array $params): void
    {
        $this->db->insert('jobs', [
            'id' => $id, 'user_id' => $userId, 'name' => $name, 'provider' => $provider, 'status' => 'pending',
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'progress' => json_encode(['percent' => 0, 'message' => 'Queued']),
            'created_at' => now_utc(), 'updated_at' => now_utc(),
        ]);
    }

    public function find(string $id, ?int $scopeUser = null): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $id)) {
            return null;
        }
        $sql = 'SELECT j.*, u.name AS user_name FROM {jobs} j LEFT JOIN {users} u ON u.id = j.user_id WHERE j.id = ?';
        $p = [$id];
        if ($scopeUser !== null) {
            $sql .= ' AND j.user_id = ?';
            $p[] = $scopeUser;
        }
        $j = $this->db->first($sql, $p);
        return $j ? self::decode($j) : null;
    }

    public static function decode(array $j): array
    {
        $j['params'] = json_decode((string) $j['params'], true) ?: [];
        $j['progress'] = json_decode((string) ($j['progress'] ?? ''), true) ?: [];
        return $j;
    }

    public function paginate(?int $scopeUser, array $f, int $page, int $per): array
    {
        $w = ['1=1'];
        $p = [];
        if ($scopeUser !== null) {
            $w[] = 'j.user_id = ?';
            $p[] = $scopeUser;
        }
        if (!empty($f['status'])) {
            $w[] = 'j.status = ?';
            $p[] = $f['status'];
        }
        if (!empty($f['q'])) {
            $w[] = 'j.name LIKE ?';
            $p[] = Database::like($f['q']);
        }
        $where = implode(' AND ', $w);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {jobs} j WHERE $where", $p);
        $rows = $this->db->all("SELECT j.*, u.name AS user_name FROM {jobs} j LEFT JOIN {users} u ON u.id = j.user_id WHERE $where ORDER BY j.created_at DESC LIMIT ? OFFSET ?", array_merge($p, [$per, ($page - 1) * $per]));
        return ['rows' => array_map([self::class, 'decode'], $rows), 'total' => $total];
    }

    public function listForSelect(?int $scopeUser, int $limit = 200): array
    {
        $sql = 'SELECT id, name, places_found, created_at FROM {jobs}' . ($scopeUser !== null ? ' WHERE user_id = ?' : '') . ' ORDER BY created_at DESC LIMIT ' . (int) $limit;
        return $this->db->all($sql, $scopeUser !== null ? [$scopeUser] : []);
    }

    /** Atomically claim the oldest pending job (SKIP LOCKED: safe with several workers). */
    public function claim(string $workerId): ?array
    {
        return $this->db->transaction(function () use ($workerId) {
            $row = $this->db->first("SELECT id FROM {jobs} WHERE status = 'pending' AND cancel_requested = 0 ORDER BY created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
            if (!$row) {
                return null;
            }
            $this->db->query("UPDATE {jobs} SET status = 'running', locked_by = ?, heartbeat_at = UTC_TIMESTAMP(), attempts = attempts + 1,
                started_at = COALESCE(started_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$workerId, $row['id']]);
            return $this->find($row['id']);
        });
    }

    /** Jobs whose worker died: back to pending (resume) or failed after max attempts. */
    public function recoverStale(int $staleMinutes, int $maxAttempts): int
    {
        $n = $this->db->query("UPDATE {jobs} SET status = 'failed', error = 'The worker stopped repeatedly while running this job.', finished_at = UTC_TIMESTAMP(), locked_by = NULL, updated_at = UTC_TIMESTAMP()
            WHERE status IN ('running','enriching') AND heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE) AND attempts >= ?", [$staleMinutes, $maxAttempts])->rowCount();
        $n += $this->db->query("UPDATE {jobs} SET status = 'pending', locked_by = NULL, updated_at = UTC_TIMESTAMP()
            WHERE status IN ('running','enriching') AND heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)", [$staleMinutes])->rowCount();
        return $n;
    }

    public function heartbeat(string $id, array $progress, ?string $status = null): void
    {
        $data = ['progress' => json_encode($progress, JSON_UNESCAPED_UNICODE), 'heartbeat_at' => now_utc(), 'updated_at' => now_utc()];
        if ($status) {
            $data['status'] = $status;
        }
        $this->db->update('jobs', $data, 'id = ?', [$id]);
    }

    public function cancelRequested(string $id): bool
    {
        return (bool) $this->db->value('SELECT cancel_requested FROM {jobs} WHERE id = ?', [$id]);
    }

    public function bump(string $id, int $apiCalls): void
    {
        $this->db->query('UPDATE {jobs} SET api_calls = api_calls + ?, places_found = (SELECT COUNT(*) FROM {places} WHERE job_id = ?) WHERE id = ?', [$apiCalls, $id, $id]);
    }

    public function finish(string $id, string $status, ?string $error, array $warnings, array $progress): void
    {
        $this->db->query("UPDATE {jobs} SET status = ?, error = ?, warnings = ?, progress = ?, finished_at = UTC_TIMESTAMP(), locked_by = NULL, updated_at = UTC_TIMESTAMP(),
            places_found = (SELECT COUNT(*) FROM {places} WHERE job_id = ?),
            emails_found = (SELECT COUNT(*) FROM {places} WHERE job_id = ? AND emails IS NOT NULL AND emails <> '') WHERE id = ?",
            [$status, $error ? mb_substr($error, 0, 1000) : null, $warnings ? implode("\n", $warnings) : null, json_encode($progress, JSON_UNESCAPED_UNICODE), $id, $id, $id]);
    }

    public function requestCancel(string $id): void
    {
        $this->db->query("UPDATE {jobs} SET cancel_requested = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
        $this->db->query("UPDATE {jobs} SET status = 'cancelled', finished_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'", [$id]);
    }

    public function retry(string $id): void
    {
        $this->db->query("UPDATE {jobs} SET status = 'pending', cancel_requested = 0, error = NULL, attempts = 0, finished_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('failed','cancelled','done')", [$id]);
    }

    public function delete(string $id): void
    {
        $this->db->delete('jobs', 'id = ?', [$id]);
    }

    public function countByStatus(?int $scopeUser): array
    {
        $rows = $this->db->all('SELECT status, COUNT(*) c FROM {jobs}' . ($scopeUser !== null ? ' WHERE user_id = ?' : '') . ' GROUP BY status', $scopeUser !== null ? [$scopeUser] : []);
        return array_column($rows, 'c', 'status');
    }

    public function recent(?int $scopeUser, int $n = 6): array
    {
        return array_map([self::class, 'decode'], $this->db->all('SELECT * FROM {jobs}' . ($scopeUser !== null ? ' WHERE user_id = ?' : '') . ' ORDER BY created_at DESC LIMIT ' . $n, $scopeUser !== null ? [$scopeUser] : []));
    }

    public function createdInLastHour(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {jobs} WHERE user_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)', [$userId]);
    }
}
