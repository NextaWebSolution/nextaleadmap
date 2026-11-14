<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AuditRepository
{
    public function __construct(private Database $db)
    {
    }

    public function paginate(array $f, int $page, int $per): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($f['action'])) {
            $w[] = 'a.action LIKE ?';
            $p[] = Database::like($f['action']);
        }
        if (!empty($f['user'])) {
            $w[] = 'a.user_id = ?';
            $p[] = (int) $f['user'];
        }
        if (!empty($f['from'])) {
            $w[] = 'a.created_at >= ?';
            $p[] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $w[] = 'a.created_at <= ?';
            $p[] = $f['to'] . ' 23:59:59';
        }
        $where = implode(' AND ', $w);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {audit_logs} a WHERE $where", $p);
        $rows = $this->db->all("SELECT a.*, u.name AS user_name, u.email AS user_email FROM {audit_logs} a LEFT JOIN {users} u ON u.id = a.user_id
            WHERE $where ORDER BY a.id DESC LIMIT ? OFFSET ?", array_merge($p, [$per, ($page - 1) * $per]));
        return ['rows' => $rows, 'total' => $total];
    }

    public function prune(int $days): int
    {
        return $days > 0 ? $this->db->query('DELETE FROM {audit_logs} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)', [$days])->rowCount() : 0;
    }
}
