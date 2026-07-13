<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlExecutor;

/**
 * @extends SqlExecutor<SqliteResult, SqliteStatement>
 */
interface SqliteExecutor extends SqlExecutor
{
    public function query(string $sql): SqliteResult;

    public function prepare(string $sql): SqliteStatement;

    public function execute(string $sql, array $params = []): SqliteResult;
}
