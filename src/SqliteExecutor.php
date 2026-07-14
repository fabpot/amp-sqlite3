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
