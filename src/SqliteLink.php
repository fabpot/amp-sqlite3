<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlLink;

/**
 * @extends SqlLink<SqliteResult, SqliteStatement, SqliteTransaction>
 */
interface SqliteLink extends SqliteExecutor, SqlLink
{
    public function beginTransaction(): SqliteTransaction;

    public function openBlob(
        string $table,
        string $column,
        int $rowId,
        string $database = 'main',
        SqliteBlobMode $mode = SqliteBlobMode::ReadOnly,
    ): SqliteBlobStream;
}
