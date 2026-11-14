<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Reversible migrations. Each file in database/migrations returns
 * ['up' => fn(Database $db) => ..., 'down' => fn(Database $db) => ...].
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    private function ensureTable(): void
    {
        $this->db->pdo->exec($this->db->sql('CREATE TABLE IF NOT EXISTS {migrations} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL UNIQUE,
            batch INT UNSIGNED NOT NULL,
            ran_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files, SORT_STRING);
        return array_map('basename', $files);
    }

    public function pending(): array
    {
        $this->ensureTable();
        $ran = array_column($this->db->all('SELECT migration FROM {migrations}'), 'migration');
        return array_values(array_diff($this->files(), $ran));
    }

    /** @return array{batch:int, ran:list<string>} */
    public function migrate(): array
    {
        $pending = $this->pending();
        $batch = (int) $this->db->value('SELECT COALESCE(MAX(batch),0) FROM {migrations}') + 1;
        $ran = [];
        foreach ($pending as $file) {
            $m = $this->load($file);
            ($m['up'])($this->db);
            $this->db->insert('migrations', ['migration' => $file, 'batch' => $batch, 'ran_at' => now_utc()]);
            $ran[] = $file;
        }
        return ['batch' => $ran ? $batch : 0, 'ran' => $ran];
    }

    /** Roll back one batch (latest by default). */
    public function rollback(?int $batch = null): array
    {
        $this->ensureTable();
        $batch ??= (int) $this->db->value('SELECT MAX(batch) FROM {migrations}');
        if ($batch <= 0) {
            return [];
        }
        $rows = $this->db->all('SELECT migration FROM {migrations} WHERE batch = ? ORDER BY id DESC', [$batch]);
        $done = [];
        foreach ($rows as $r) {
            $m = $this->load($r['migration']);
            if (isset($m['down'])) {
                ($m['down'])($this->db);
            }
            $this->db->delete('migrations', 'migration = ?', [$r['migration']]);
            $done[] = $r['migration'];
        }
        return $done;
    }

    private function load(string $file): array
    {
        if (!preg_match('/^[0-9]{4}_[a-z0-9_]+\.php$/', $file)) {
            throw new RuntimeException('Invalid migration name: ' . $file);
        }
        $m = require $this->dir . '/' . $file;
        if (!is_array($m) || !isset($m['up'])) {
            throw new RuntimeException('Invalid migration: ' . $file);
        }
        return $m;
    }
}
