<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Closable;
use Amp\Sql\SqlResult;

/**
 * @extends SqlResult<null|bool|int|float|string|SqliteBlob>
 */
interface SqliteResult extends SqlResult, Closable
{
    public function getNextResult(): ?self;

    public function getLastInsertId(): int;
}
