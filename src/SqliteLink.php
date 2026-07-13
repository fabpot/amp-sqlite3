<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlLink;

/**
 * @extends SqlLink<SqliteResult, SqliteStatement, SqliteTransaction>
 */
interface SqliteLink extends SqlLink, SqliteExecutor
{
    public function beginTransaction(): SqliteTransaction;
}
