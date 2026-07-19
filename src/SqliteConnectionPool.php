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

use Amp\Sql\Common\SqlCommonConnectionPool;
use Amp\Sql\SqlConnector;
use Amp\Sql\SqlResult;
use Amp\Sql\SqlStatement;
use Amp\Sql\SqlTransaction;
use Amp\Sql\SqlTransactionIsolation;
use Fabpot\Amp\Sqlite\Internal\PooledResult;
use Fabpot\Amp\Sqlite\Internal\PooledStatement;
use Fabpot\Amp\Sqlite\Internal\PooledTransaction;
use Fabpot\Amp\Sqlite\Internal\StatementPool;

/**
 * @extends SqlCommonConnectionPool<SqliteConfig, SqliteResult, SqliteStatement, SqliteTransaction, SqliteConnection>
 */
final class SqliteConnectionPool extends SqlCommonConnectionPool implements SqliteConnection
{
    public const DEFAULT_MAX_CONNECTIONS = 10;

    /**
     * @param positive-int $maxConnections
     * @param positive-int $idleTimeout
     * @param SqlConnector<SqliteConfig, SqliteConnection>|null $connector
     */
    public function __construct(
        SqliteConfig $config,
        int $maxConnections = self::DEFAULT_MAX_CONNECTIONS,
        int $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
        ?SqlConnector $connector = null,
        ?SqliteTransactionMode $transactionIsolation = null,
    ) {
        if ($config->getDatabase() === ':memory:') {
            throw new \RuntimeException('Connection pools cannot use :memory: databases, as every pooled connection would open a separate database');
        }

        parent::__construct(
            config: $config,
            connector: $connector ?? new SqliteConnector(),
            maxConnections: $maxConnections,
            idleTimeout: $idleTimeout,
            transactionIsolation: $transactionIsolation ?? $config->getTransactionMode(),
        );
    }

    public function getTransactionIsolation(): SqliteTransactionMode
    {
        /** @var SqliteTransactionMode */
        return parent::getTransactionIsolation();
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        if (!$isolation instanceof SqliteTransactionMode) {
            throw new \TypeError('SQLite connection pools only accept SqliteTransactionMode');
        }

        parent::setTransactionIsolation($isolation);
    }

    public function getConfig(): SqliteConfig
    {
        /** @var SqliteConfig */
        return parent::getConfig();
    }

    public function query(string $sql): SqliteResult
    {
        $result = parent::query($sql);
        \assert($result instanceof SqliteResult);

        return $result;
    }

    public function execute(string $sql, #[\SensitiveParameter] array $params = []): SqliteResult
    {
        $connection = $this->pop();

        try {
            $result = $connection->execute($sql, $params);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        return $this->createResult($result, fn () => $this->push($connection));
    }

    public function prepare(string $sql): SqliteStatement
    {
        $statement = parent::prepare($sql);
        \assert($statement instanceof SqliteStatement);

        return $statement;
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
        $connection = $this->pop();

        try {
            $blob = $connection->openBlob($table, $column, $rowId, $database, $mode);
        } catch (\Throwable $exception) {
            $this->push($connection);
            throw $exception;
        }

        $blob->onClose(fn () => $this->push($connection));

        return $blob;
    }

    public function backup(string $destinationPath, string $database = 'main'): void
    {
        $connection = $this->pop();

        try {
            $connection->backup($destinationPath, $database);
        } finally {
            $this->push($connection);
        }
    }

    public function restore(string $sourcePath, string $database = 'main'): void
    {
        $connection = $this->pop();

        try {
            $connection->restore($sourcePath, $database);
        } finally {
            $this->push($connection);
        }
    }

    public function extractConnection(): SqliteConnection
    {
        $connection = parent::extractConnection();
        \assert($connection instanceof SqliteConnection);

        return $connection;
    }

    protected function createResult(SqlResult $result, \Closure $release): SqliteResult
    {
        \assert($result instanceof SqliteResult);

        return new PooledResult($result, $release);
    }

    protected function createStatement(SqlStatement $statement, \Closure $release): SqliteStatement
    {
        \assert($statement instanceof SqliteStatement);

        return new PooledStatement($statement, $release);
    }

    protected function createStatementPool(string $sql, \Closure $prepare): SqliteStatement
    {
        return new StatementPool($this, $sql, $prepare);
    }

    protected function createTransaction(SqlTransaction $transaction, \Closure $release): SqliteTransaction
    {
        \assert($transaction instanceof SqliteTransaction);

        return new PooledTransaction($transaction, $release);
    }
}
