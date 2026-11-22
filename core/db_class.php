<?php
/**
 * db_class.php — Lightweight PDO database wrapper.
 * Credentials come from db_cred.php (loaded by core.php).
 */

declare(strict_types=1);

class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                SERVER,
                DATABASE,
                DB_CHARSET
            );
            self::$pdo = new PDO($dsn, USERNAME, PASSWD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** Run a prepared statement and return it. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Return one row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Return all rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Return a single scalar value or null. */
    public static function value(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT a row and return the new auto-increment id. */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(fn($c) => ':' . $c, $cols))
        );
        self::run($sql, $row);
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE rows and return the number of affected rows. */
    public static function update(string $table, array $row, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($row)));
        return self::run(
            "UPDATE $table SET $set WHERE $where",
            [...array_values($row), ...$params]
        )->rowCount();
    }

    /** DELETE rows and return the number of affected rows. */
    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run("DELETE FROM $table WHERE $where", $params)->rowCount();
    }
}
