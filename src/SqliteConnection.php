<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlConnection;

/**
 * @extends SqlConnection<SqliteConfig, SqliteResult, SqliteStatement, SqliteTransaction>
 */
interface SqliteConnection extends SqliteLink, SqlConnection
{
    public function getConfig(): SqliteConfig;
}
