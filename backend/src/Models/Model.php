<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use PDOStatement;

abstract class Model
{
    protected function run(string $sql, array $params = []): PDOStatement
    {
        return DB::run($sql, $params);
    }

    protected function one(string $sql, array $params = []): ?array
    {
        return DB::one($sql, $params);
    }

    protected function all(string $sql, array $params = []): array
    {
        return DB::all($sql, $params);
    }

    protected function value(string $sql, array $params = []): mixed
    {
        return DB::value($sql, $params);
    }

    protected function insert(string $table, array $row): int
    {
        return DB::insert($table, $row);
    }

    protected function update(string $table, array $row, string $where, array $params = []): int
    {
        return DB::update($table, $row, $where, $params);
    }

    protected function transaction(callable $callback): mixed
    {
        return DB::tx($callback);
    }
}
