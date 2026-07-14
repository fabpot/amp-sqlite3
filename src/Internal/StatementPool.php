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

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\Sql\Common\SqlStatementPool;
use Amp\Sql\SqlResult;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;

/**
 * @extends SqlStatementPool<SqliteConfig, SqliteResult, SqliteStatement, SqliteTransaction>
 */
final class StatementPool extends SqlStatementPool implements SqliteStatement
{
    public function execute(array $params = []): SqliteResult
    {
        $result = parent::execute($params);
        \assert($result instanceof SqliteResult);

        return $result;
    }

    protected function createResult(SqlResult $result, \Closure $release): SqliteResult
    {
        \assert($result instanceof SqliteResult);

        return new PooledResult($result, $release);
    }
}
