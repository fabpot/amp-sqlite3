<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlTransaction;

/**
 * @extends SqlTransaction<SqliteResult, SqliteStatement, SqliteTransaction>
 */
interface SqliteTransaction extends SqlTransaction, SqliteLink
{
}
