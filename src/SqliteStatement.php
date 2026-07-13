<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlStatement;

/**
 * @extends SqlStatement<SqliteResult>
 */
interface SqliteStatement extends SqlStatement
{
    public function execute(array $params = []): SqliteResult;
}
