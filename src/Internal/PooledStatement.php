<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\Sql\Common\SqlPooledStatement;
use Amp\Sql\SqlResult;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;

/**
 * @extends SqlPooledStatement<SqliteResult, SqliteStatement>
 */
final class PooledStatement extends SqlPooledStatement implements SqliteStatement
{
    /**
     * @param \Closure():void $release
     */
    public function __construct(SqliteStatement $statement, \Closure $release, ?\Closure $awaitBusyResource = null)
    {
        parent::__construct($statement, $release, $awaitBusyResource);
    }

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
