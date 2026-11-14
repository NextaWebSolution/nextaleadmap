<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** GeoNames city presets (CC BY 4.0) for autocomplete and offline geocoding. */
final class CityRepository
{
    public function __construct(private Database $db)
    {
    }

    public static function fold(string $s): string
    {
        static $t = null;
        $s = mb_strtolower(trim($s));
        if (class_exists(\Transliterator::class)) {
            $t ??= \Transliterator::create('Any-Latin; Latin-ASCII; Lower()') ?: false;
            if ($t) {
                $s = (string) $t->transliterate($s);
            }
        } else {
            $s = (string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
        }
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    public function search(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $parts = array_map('trim', explode(',', $q, 2));
        $name = self::fold($parts[0]);
        $hint = isset($parts[1]) ? self::fold($parts[1]) : '';
        if ($name === '') {
            return [];
        }
        $rows = $this->db->all('SELECT name, region, country, lat, lon FROM {cities} WHERE search_name LIKE ? ORDER BY (search_name = ?) DESC, rank_order ASC LIMIT 60',
            [addcslashes($name, '%_\\') . '%', $name]);
        if ($hint !== '') {
            $rows = array_values(array_filter($rows, static fn ($r) => str_contains(self::fold($r['region'] . ' ' . $r['country']), $hint)));
        }
        return array_map(static fn ($r) => [
            'label' => $r['name'] . ($r['region'] && $r['region'] !== $r['name'] ? ', ' . $r['region'] : '') . ', ' . $r['country'],
            'name' => $r['name'], 'region' => $r['region'], 'country' => strtolower($r['country']),
            'lat' => (float) $r['lat'], 'lon' => (float) $r['lon'],
        ], array_slice($rows, 0, $limit));
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {cities}');
    }

    /** Import the bundled TSV (name, region, country, lat, lon) in batches. */
    public function import(string $gzFile): int
    {
        $this->db->pdo->exec('TRUNCATE TABLE ' . $this->db->table('cities'));
        $fh = gzopen($gzFile, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Cannot open city presets');
        }
        $batch = [];
        $n = 0;
        while (($line = gzgets($fh)) !== false) {
            $c = explode("\t", rtrim($line, "\r\n"));
            if (count($c) < 5 || !is_numeric($c[3]) || !is_numeric($c[4])) {
                continue;
            }
            $n++;
            array_push($batch, mb_substr($c[0], 0, 190), mb_substr($c[1], 0, 190), strtoupper(substr($c[2], 0, 2)), (float) $c[3], (float) $c[4], mb_substr(self::fold($c[0]), 0, 190), $n);
            if (count($batch) >= 7 * 1000) {
                $this->flush($batch);
                $batch = [];
            }
        }
        gzclose($fh);
        if ($batch) {
            $this->flush($batch);
        }
        return $n;
    }

    private function flush(array $values): void
    {
        $rows = intdiv(count($values), 7);
        $sql = 'INSERT INTO {cities} (name, region, country, lat, lon, search_name, rank_order) VALUES ' . implode(',', array_fill(0, $rows, '(?,?,?,?,?,?,?)'));
        $this->db->query($sql, $values);
    }
}
