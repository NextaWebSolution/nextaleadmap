<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Core\Database;
use PDO;
use RuntimeException;

/** Pure-PHP logical dump of the application's tables (gzip SQL). Works on shared hosting without mysqldump. */
final class DatabaseBackup
{
    public function __construct(private Database $db)
    {
    }

    public function tables(): array
    {
        $like = addcslashes($this->db->prefix, '%_\\') . '%';
        return array_column($this->db->all('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', [$like]), 't');
    }

    public function dump(string $file): int
    {
        $gz = gzopen($file, 'wb6');
        if (!$gz) {
            throw new RuntimeException('Cannot write database backup');
        }
        $pdo = $this->db->pdo;
        gzwrite($gz, "-- NextaLeadMap database backup " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $rows = 0;
        foreach ($this->tables() as $t) {
            $q = '`' . str_replace('`', '``', $t) . '`';
            $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
            gzwrite($gz, "DROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n\n");
            $last = null;
            $pkRow = $pdo->query("SHOW KEYS FROM $q WHERE Key_name = 'PRIMARY'")->fetch(PDO::FETCH_ASSOC);
            $pk = $pkRow['Column_name'] ?? null;
            $offset = 0;
            while (true) {
                if ($pk) {
                    $sql = "SELECT * FROM $q" . ($last !== null ? ' WHERE `' . $pk . '` > ' . $pdo->quote((string) $last) : '') . " ORDER BY `$pk` LIMIT 500";
                } else {
                    $sql = "SELECT * FROM $q LIMIT 500 OFFSET $offset";
                    $offset += 500;
                }
                $batch = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                if (!$batch) {
                    break;
                }
                $vals = [];
                foreach ($batch as $r) {
                    $vals[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)) . ')';
                    if ($pk) {
                        $last = $r[$pk];
                    }
                }
                $cols = implode(',', array_map(static fn ($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($batch[0])));
                gzwrite($gz, "INSERT INTO $q ($cols) VALUES\n" . implode(",\n", $vals) . ";\n");
                $rows += count($batch);
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        return $rows;
    }

    /** Restore a dump made by dump(). Statements are split on ";\n" boundaries written by this class. */
    public function restore(string $file): void
    {
        $gz = gzopen($file, 'rb');
        if (!$gz) {
            throw new RuntimeException('Cannot read backup');
        }
        $buf = '';
        while (!gzeof($gz)) {
            $buf .= gzgets($gz, 1 << 20);
            if (str_ends_with($buf, ";\n")) {
                $stmt = trim((string) preg_replace('/^--[^\n]*\n/m', '', $buf));
                $buf = '';
                if ($stmt !== '') {
                    $this->db->pdo->exec($stmt);
                }
            }
        }
        gzclose($gz);
    }
}
