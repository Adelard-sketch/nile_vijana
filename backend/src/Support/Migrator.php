<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\DB;

/**
 * Small helpers for database migrations (database/migrations/*.php).
 * Every helper checks the current structure first, so a migration can be
 * run on any existing database (MySQL 8 or MariaDB) without losing data.
 */
final class Migrator
{
    public function hasTable(string $table): bool
    {
        return (bool) DB::value(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }

    public function hasColumn(string $table, string $column): bool
    {
        return (bool) DB::value(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    /** Add a column only if it is missing. $definition example: "VARCHAR(255) NULL AFTER bio" */
    public function addColumn(string $table, string $column, string $definition): void
    {
        if (!$this->hasColumn($table, $column)) {
            DB::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            $this->log("+ $table.$column");
        }
    }

    /** Make sure an ENUM column allows every value in $values (keeps existing values). */
    public function extendEnum(string $table, string $column, array $values, string $tail = 'NOT NULL'): void
    {
        $type = (string) DB::value(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $m);
        $current = $m[1] ?? [];
        $missing = array_diff($values, $current);
        if (!$missing) {
            return;
        }
        $all = array_merge($current, array_values($missing));
        $default = (string) DB::value(
            'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        $default = trim($default, "'");
        $enum = "ENUM('" . implode("','", $all) . "')";
        $sql = "ALTER TABLE `$table` MODIFY `$column` $enum $tail" . ($default !== '' && $default !== 'NULL' ? " DEFAULT '$default'" : '');
        DB::pdo()->exec($sql);
        $this->log("~ $table.$column += " . implode(', ', $missing));
    }

    public function createTable(string $table, string $sql): void
    {
        if (!$this->hasTable($table)) {
            DB::pdo()->exec($sql);
            $this->log("+ table $table");
        }
    }

    /** Run every CREATE TABLE IF NOT EXISTS statement in schema.sql (adds tables an older database lacks). */
    public function createMissingTablesFromSchema(string $schemaFile): void
    {
        $sql = (string) file_get_contents($schemaFile);
        preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?\s*\(.*?\)\s*ENGINE=InnoDB[^;]*;/s', $sql, $m, PREG_SET_ORDER);
        DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($m as [$statement, $table]) {
            if (!$this->hasTable($table)) {
                DB::pdo()->exec($statement);
                $this->log("+ table $table");
            }
        }
        DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function log(string $line): void
    {
        if (PHP_SAPI === 'cli') {
            echo '    ' . $line . PHP_EOL;
        }
    }
}
