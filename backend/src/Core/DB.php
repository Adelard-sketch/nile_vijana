<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin wrapper around PDO. Every query uses prepared statements,
 * so user input never ends up inside SQL text (no SQL injection).
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = config('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['name']);
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Keep MySQL's NOW() in the same time zone as PHP's date().
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    /** Return the configured table prefix (e.g. "nv_"). Empty string when not set. */
    public static function prefix(): string
    {
        return (string) config('db.prefix', '');
    }

    /**
     * Prefix a bare table name.
     * Use this inside raw SQL strings: DB::tbl('users') → 'nv_users'
     * Example: DB::run('SELECT * FROM ' . DB::tbl('users') . ' WHERE id = ?', [$id])
     */
    public static function tbl(string $table): string
    {
        return self::prefix() . $table;
    }

    /**
     * Replace every occurrence of {table_name} tokens in a SQL string with the
     * prefixed table name, so raw queries stay readable and work on any prefix.
     * Example: DB::sql('SELECT * FROM {users} WHERE id = ?')
     *          becomes 'SELECT * FROM nv_users WHERE id = ?'
     */
    public static function sql(string $query): string
    {
        $prefix = self::prefix();
        return preg_replace_callback('/\{(\w+)\}/', static fn ($m) => $prefix . $m[1], $query);
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare(self::sql($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT a row (column names come from our code, never from users). Returns the new id. */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::prefix() . $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($row);
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE rows: DB::update('beats', ['status' => 'live'], 'id = ?', [$id]) */
    public static function update(string $table, array $row, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($row)));
        return self::run('UPDATE ' . self::prefix() . $table . " SET $set WHERE $where", [...array_values($row), ...$params])->rowCount();
    }

    /**
     * Run $fn inside a database transaction. Everything inside either
     * succeeds together or is rolled back together. Nested calls join
     * the outer transaction.
     */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
