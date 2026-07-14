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

use Amp\Sql\Common\SqlPooledTransaction;
use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;
use Amp\Sql\SqlTransaction;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteBlobStream;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;

/**
 * @extends SqlPooledTransaction<SqliteResult, SqliteStatement, SqliteTransaction>
 */
final class PooledTransaction extends SqlPooledTransaction implements SqliteTransaction
{
    private readonly SqliteTransaction $transaction;

    /**
     * @param \Closure():void $release
     */
    public function __construct(SqliteTransaction $transaction, \Closure $release)
    {
        parent::__construct($transaction, $release);
        $this->transaction = $transaction;
    }

    public function query(string $sql): SqliteResult
    {
        $result = parent::query($sql);
        \assert($result instanceof SqliteResult);

        return $result;
    }

    public function prepare(string $sql): SqliteStatement
    {
        $statement = parent::prepare($sql);
        \assert($statement instanceof SqliteStatement);

        return $statement;
    }

    public function execute(string $sql, #[\SensitiveParameter] array $params = []): SqliteResult
    {
        $result = parent::execute($sql, $params);
        \assert($result instanceof SqliteResult);

        return $result;
    }

    public function beginTransaction(): SqliteTransaction
    {
        $transaction = parent::beginTransaction();
        \assert($transaction instanceof SqliteTransaction);

        return $transaction;
    }

    public function openBlob(
        string $table,
        string $column,
        int $rowId,
        string $database = 'main',
        SqliteBlobMode $mode = SqliteBlobMode::ReadOnly,
    ): SqliteBlobStream {
        return $this->transaction->openBlob($table, $column, $rowId, $database, $mode);
    }

    public function getIsolation(): SqliteTransactionMode
    {
        $isolation = $this->transaction->getIsolation();
        \assert($isolation instanceof SqliteTransactionMode);

        return $isolation;
    }

    protected function createStatement(SqlStatement $statement, \Closure $release): SqliteStatement
    {
        \assert($statement instanceof SqliteStatement);

        return new PooledStatement($statement, $release);
    }

    protected function createResult(SqlResult $result, \Closure $release): SqliteResult
    {
        \assert($result instanceof SqliteResult);

        return new PooledResult($result, $release);
    }

    protected function createTransaction(SqlTransaction $transaction, \Closure $release): SqliteTransaction
    {
        \assert($transaction instanceof SqliteTransaction);

        return new self($transaction, $release);
    }
}
