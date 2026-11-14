<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PlaceRepository
{
    public const SOCIALS = ['instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'x'];
    public const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost'];
    private const COLS = ['provider_place_id', 'title', 'category', 'categories', 'address', 'phone', 'website', 'rating', 'review_count',
        'latitude', 'longitude', 'price_level', 'business_status', 'opening_hours', 'plus_code', 'maps_url', 'thumbnail', 'raw'];

    /** Column limits (strict SQL mode rejects longer values). */
    private const MAXLEN = ['provider_place_id' => 190, 'title' => 500, 'category' => 255, 'address' => 1000, 'phone' => 64, 'website' => 1000,
        'price_level' => 40, 'business_status' => 40, 'plus_code' => 40, 'maps_url' => 1000, 'thumbnail' => 1000, 'categories' => 60000, 'opening_hours' => 60000];

    public function __construct(private Database $db)
    {
    }

    /** Insert normalized places; existing (job, key) rows keep their enrichment. Returns inserted count. */
    public function upsertMany(string $jobId, string $keyword, array $places): int
    {
        $inserted = 0;
        foreach ($places as $p) {
            $row = ['job_id' => $jobId, 'place_key' => mb_substr($p['place_key'], 0, 190), 'keyword' => mb_substr($keyword, 0, 500)];
            foreach (self::COLS as $c) {
                $v = $p[$c] ?? null;
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $row[$c] = $v;
            }
            foreach (self::MAXLEN as $c => $n) {
                if (is_string($row[$c] ?? null)) {
                    $row[$c] = mb_substr($row[$c], 0, $n);
                }
            }
            $row['created_at'] = $row['updated_at'] = now_utc();
            $cols = array_keys($row);
            $sql = 'INSERT INTO {places} (' . implode(',', array_map([$this->db, 'ident'], $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')
                ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at),
                rating = COALESCE(VALUES(rating), rating), review_count = COALESCE(VALUES(review_count), review_count),
                phone = COALESCE(phone, VALUES(phone)), website = COALESCE(website, VALUES(website))';
            $st = $this->db->query($sql, array_values($row));
            if ($st->rowCount() === 1) {
                $inserted++;
            }
        }
        return $inserted;
    }

    /** Keys from $keys that already exist in any of $jobIds (for "exclude earlier results"). */
    public function existingKeys(array $keys, array $jobIds): array
    {
        if (!$keys || !$jobIds) {
            return [];
        }
        $out = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $sql = 'SELECT DISTINCT place_key FROM {places} WHERE job_id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ') AND place_key IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            foreach ($this->db->all($sql, array_merge($jobIds, $chunk)) as $r) {
                $out[$r['place_key']] = true;
            }
        }
        return $out;
    }

    public function forEnrichment(string $jobId, int $afterId, int $limit): array
    {
        return $this->db->all("SELECT id, title, phone, category, website, emails FROM {places}
            WHERE job_id = ? AND id > ? AND website IS NOT NULL AND website <> '' AND enriched_at IS NULL ORDER BY id LIMIT ?", [$jobId, $afterId, $limit]);
    }

    public function saveEnrichment(int $id, array $d): void
    {
        $data = ['enriched_at' => now_utc(), 'updated_at' => now_utc()];
        foreach (array_merge(['emails', 'email_host', 'website_status', 'intent_score'], self::SOCIALS) as $k) {
            if (array_key_exists($k, $d)) {
                $v = $d[$k] === '' ? null : $d[$k];
                $data[$k] = is_string($v) ? mb_substr($v, 0, $k === 'emails' ? 1000 : ($k === 'email_host' ? 40 : ($k === 'website_status' ? 30 : 500))) : $v;
            }
        }
        if (isset($d['tech'])) {
            $data['tech'] = json_encode($d['tech']);
        }
        $this->db->update('places', $data, 'id = ?', [$id]);
    }

    public function find(int $id, ?int $scopeUser): ?array
    {
        $sql = 'SELECT p.*, j.name AS job_name, j.user_id FROM {places} p JOIN {jobs} j ON j.id = p.job_id WHERE p.id = ?';
        $prm = [$id];
        if ($scopeUser !== null) {
            $sql .= ' AND j.user_id = ?';
            $prm[] = $scopeUser;
        }
        return $this->db->first($sql, $prm);
    }

    /** Build WHERE for lead search filters. */
    private function filters(?int $scopeUser, array $f): array
    {
        $w = ['1=1'];
        $p = [];
        if ($scopeUser !== null) {
            $w[] = 'j.user_id = ?';
            $p[] = $scopeUser;
        }
        if (!empty($f['job'])) {
            $w[] = 'p.job_id = ?';
            $p[] = (string) $f['job'];
        }
        if (!empty($f['q'])) {
            $w[] = '(p.title LIKE ? OR p.emails LIKE ? OR p.phone LIKE ? OR p.website LIKE ? OR p.address LIKE ? OR p.category LIKE ?)';
            array_push($p, ...array_fill(0, 6, Database::like((string) $f['q'])));
        }
        if (!empty($f['status']) && in_array($f['status'], self::LEAD_STATUSES, true)) {
            $w[] = 'p.lead_status = ?';
            $p[] = $f['status'];
        }
        $yn = ['email' => "p.emails IS NOT NULL AND p.emails <> ''", 'website' => "p.website IS NOT NULL AND p.website <> ''", 'phone' => "p.phone IS NOT NULL AND p.phone <> ''"];
        foreach ($yn as $k => $cond) {
            if (($f['has_' . $k] ?? '') === 'yes') {
                $w[] = "($cond)";
            } elseif (($f['has_' . $k] ?? '') === 'no') {
                $w[] = "NOT ($cond)";
            }
        }
        if (isset($f['min_rating']) && is_numeric($f['min_rating']) && (float) $f['min_rating'] > 0) {
            $w[] = 'p.rating >= ?';
            $p[] = (float) $f['min_rating'];
        }
        if (isset($f['min_reviews']) && is_numeric($f['min_reviews']) && (int) $f['min_reviews'] > 0) {
            $w[] = 'p.review_count >= ?';
            $p[] = (int) $f['min_reviews'];
        }
        if (isset($f['min_score']) && is_numeric($f['min_score']) && (int) $f['min_score'] > 0) {
            $w[] = 'p.intent_score >= ?';
            $p[] = (int) $f['min_score'];
        }
        return [implode(' AND ', $w), $p];
    }

    private function order(string $sort): string
    {
        return match ($sort) {
            'rating' => 'p.rating DESC, p.review_count DESC',
            'reviews' => 'p.review_count DESC',
            'score' => 'p.intent_score DESC, p.review_count DESC',
            'name' => 'p.title ASC',
            default => 'p.id DESC',
        };
    }

    public function search(?int $scopeUser, array $f, int $page, int $per): array
    {
        [$where, $p] = $this->filters($scopeUser, $f);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {places} p JOIN {jobs} j ON j.id = p.job_id WHERE $where", $p);
        $rows = $this->db->all("SELECT p.id, p.job_id, p.title, p.category, p.address, p.phone, p.website, p.emails, p.rating, p.review_count, p.intent_score,
                p.lead_status, p.instagram, p.facebook, p.linkedin, p.tiktok, p.youtube, p.x, p.maps_url, p.created_at, j.name AS job_name
            FROM {places} p JOIN {jobs} j ON j.id = p.job_id WHERE $where ORDER BY " . $this->order((string) ($f['sort'] ?? '')) . ' LIMIT ? OFFSET ?', array_merge($p, [$per, ($page - 1) * $per]));
        return ['rows' => $rows, 'total' => $total];
    }

    /** Stream all matching rows to $fn (memory-safe export). */
    public function each(?int $scopeUser, array $f, callable $fn): void
    {
        [$where, $p] = $this->filters($scopeUser, $f);
        $lastId = PHP_INT_MAX;
        while (true) {
            $rows = $this->db->all("SELECT p.* FROM {places} p JOIN {jobs} j ON j.id = p.job_id WHERE $where AND p.id < ? ORDER BY p.id DESC LIMIT 1000", array_merge($p, [$lastId]));
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $fn($r);
                $lastId = (int) $r['id'];
            }
        }
    }

    public function updateLead(int $id, array $d): void
    {
        $data = ['updated_at' => now_utc()];
        if (isset($d['lead_status']) && in_array($d['lead_status'], self::LEAD_STATUSES, true)) {
            $data['lead_status'] = $d['lead_status'];
        }
        if (array_key_exists('notes', $d)) {
            $data['notes'] = mb_substr((string) $d['notes'], 0, 5000);
        }
        $this->db->update('places', $data, 'id = ?', [$id]);
    }

    public function deleteIds(array $ids, ?int $scopeUser): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE p FROM {places} p JOIN {jobs} j ON j.id = p.job_id WHERE p.id IN ($in)" . ($scopeUser !== null ? ' AND j.user_id = ?' : '');
        return $this->db->query($sql, $scopeUser !== null ? array_merge($ids, [$scopeUser]) : $ids)->rowCount();
    }

    public function stats(?int $scopeUser): array
    {
        $join = ' FROM {places} p JOIN {jobs} j ON j.id = p.job_id' . ($scopeUser !== null ? ' WHERE j.user_id = ?' : ' WHERE 1=1');
        $p = $scopeUser !== null ? [$scopeUser] : [];
        $row = $this->db->first("SELECT COUNT(*) total,
            SUM(p.emails IS NOT NULL AND p.emails <> '') with_email,
            SUM(p.phone IS NOT NULL AND p.phone <> '') with_phone,
            SUM(p.website IS NOT NULL AND p.website <> '') with_website,
            SUM(p.lead_status = 'won') won,
            AVG(p.rating) avg_rating $join", $p) ?? [];
        $daily = $this->db->all("SELECT DATE(p.created_at) d, COUNT(*) c, SUM(p.emails IS NOT NULL AND p.emails <> '') e $join AND p.created_at >= DATE_SUB(UTC_DATE(), INTERVAL 29 DAY) GROUP BY DATE(p.created_at) ORDER BY d", $p);
        $cats = $this->db->all("SELECT p.category, COUNT(*) c $join AND p.category IS NOT NULL AND p.category <> '' GROUP BY p.category ORDER BY c DESC LIMIT 8", $p);
        $statuses = $this->db->all("SELECT p.lead_status s, COUNT(*) c $join GROUP BY p.lead_status", $p);
        return ['totals' => $row, 'daily' => $daily, 'categories' => $cats, 'statuses' => array_column($statuses, 'c', 's')];
    }
}
