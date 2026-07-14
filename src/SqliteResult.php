<?php

declare(strict_types=1);

/*
 * This file is part of the fabpot/amphp-sqlite3 package.
 *
 * (c) Fabien Potencier <fabien@potencier.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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

    /**
     * @return list<string>|null Column names of a row-producing result, or null for a command result.
     */
    public function getColumnNames(): ?array;
}
