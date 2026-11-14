<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO wrapper. Every query uses prepared statements; table names are written as {table}
 * and expanded to the configured prefix, e.g. "SELECT * FROM {users} WHERE id = ?".
 */
final class Database
{
    private int $txDepth = 0;

    public function __construct(public readonly PDO $pdo, public readonly string $prefix = '')
    {
    }

    public static function connect(array $c): self
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['database']);
        $opts = [];
        if (!empty($c['ssl_ca']) && is_file((string) $c['ssl_ca'])) { // TLS to a remote database server
            $opts[PDO::MYSQL_ATTR_SSL_CA] = (string) $c['ssl_ca'];
            $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $pdo = new PDO($dsn, (string) $c['username'], (string) $c['password'], $opts + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $prefix = preg_replace('/[^a-z0-9_]/i', '', (string) ($c['prefix'] ?? ''));
        return new self($pdo, $prefix);
    }

    public function table(string $name): string
    {
        return '`' . $this->prefix . preg_replace('/[^a-z0-9_]/i', '', $name) . '`';
    }

    public function sql(string $sql): string
    {
        return (string) preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}/i', fn ($m) => $this->table($m[1]), $sql);
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($this->sql($sql));
        foreach (array_values(array_is_list($params) ? $params : []) as $i => $v) {
            $stmt->bindValue($i + 1, $v, self::type($v));
        }
        if (!array_is_list($params)) {
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . ltrim((string) $k, ':'), $v, self::type($v));
            }
        }
        $stmt->execute();
        return $stmt;
    }

    private static function type(mixed $v): int
    {
        return match (true) {
            is_int($v) => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_BOOL,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    public function first(string $sql, array $params = []): ?array
    {
        $r = $this->query($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Insert a row; column names are validated identifiers, values are bound. */
    public function insert(string $table, array $data): string
    {
        $cols = array_map([$this, 'ident'], array_keys($data));
        $sql = 'INSERT INTO ' . $this->table($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn ($c) => $this->ident($c) . ' = ?', array_keys($data)));
        $sql = 'UPDATE ' . $this->table($table) . ' SET ' . $set . ' WHERE ' . $where;
        return $this->query($sql, array_merge(array_values($data), $params))->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->query('DELETE FROM ' . $this->table($table) . ' WHERE ' . $where, $params)->rowCount();
    }

    public function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException('Invalid identifier');
        }
        return '`' . $name . '`';
    }

    /** @template T @param callable():T $fn @return T */
    public function transaction(callable $fn): mixed
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->txDepth++;
        try {
            $r = $fn();
            $this->txDepth--;
            if ($this->txDepth === 0) {
                $this->pdo->commit();
            }
            return $r;
        } catch (Throwable $e) {
            $this->txDepth--;
            if ($this->txDepth === 0 && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Escape a value for a LIKE pattern (the pattern itself is still bound). */
    public static function like(string $s): string
    {
        return '%' . addcslashes($s, '%_\\') . '%';
    }
}
