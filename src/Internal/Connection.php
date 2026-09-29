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

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sql\SqlTransactionIsolation;
use Amp\Sync\Lock;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteBlobStream;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnection;
use Fabpot\Amp\Sqlite\SqliteConnectionException;
use Fabpot\Amp\Sqlite\SqliteException;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use Revolt\EventLoop;

/** @internal */
final class Connection implements SqliteConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $onClose;

    /** @var \WeakMap<Result, true> */
    private \WeakMap $results;

    /** @var \WeakMap<Statement, true> */
    private \WeakMap $statements;

    /** @var \WeakMap<BlobStream, true> */
    private \WeakMap $blobs;

    private SqliteTransactionMode $transactionMode;
    private bool $closed = false;
    /** @var \WeakReference<Transaction>|null */
    private ?\WeakReference $activeTransaction = null;

    public function __construct(
        private readonly SqliteConfig $config,
        private readonly WorkerChannel $channel,
        private readonly ConnectionLeases $leases,
    ) {
        $this->onClose = new DeferredFuture();
        /** @var \WeakMap<Result, true> $results */
        $results = new \WeakMap();
        $this->results = $results;
        /** @var \WeakMap<Statement, true> $statements */
        $statements = new \WeakMap();
        $this->statements = $statements;
        /** @var \WeakMap<BlobStream, true> $blobs */
        $blobs = new \WeakMap();
        $this->blobs = $blobs;
        $this->transactionMode = $config->getTransactionMode();
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        EventLoop::queue(self::closeChannel(...), $this->channel, $this->onClose);
    }

    public function query(string $sql): SqliteResult
    {
        return $this->run($sql, [], false, false);
    }

    public function prepare(string $sql): SqliteStatement
    {
        return $this->prepareStatement($sql);
    }

    /**
     * @param array<array-key, null|bool|int|float|string|SqliteBlob> $params
     */
    public function execute(string $sql, #[\SensitiveParameter] array $params = []): SqliteResult
    {
        return $this->run($sql, $params, true, false);
    }

    public function beginTransaction(): SqliteTransaction
    {
        $this->assertCurrentTaskHoldsNoTransactionLease();
        $lock = $this->acquireConnectionLock();

        try {
            $this->executeControl('BEGIN ' . $this->transactionMode->toSql());
        } catch (\Throwable $exception) {
            $lock->release();
            throw $exception;
        }

        $this->leases->holdTransactionLock($lock);
        $transaction = new Transaction($this, $this->transactionMode);
        $this->activeTransaction = \WeakReference::create($transaction);

        return $transaction;
    }

    public function getConfig(): SqliteConfig
    {
        return $this->config;
    }

    public function executeScript(string $sql): void
    {
        $this->assertOpen();
        $lock = $this->acquireConnectionLock();

        try {
            $this->requestVoid('executeScript', $sql, ['sql' => $sql, 'transaction_mode' => $this->transactionMode->toSql()]);
        } finally {
            $lock->release();
        }
    }

    public function getTransactionIsolation(): SqliteTransactionMode
    {
        return $this->transactionMode;
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        if (!$isolation instanceof SqliteTransactionMode) {
            throw new \InvalidArgumentException('SQLite connections only accept SqliteTransactionMode');
        }

        $this->transactionMode = $isolation;
    }

    public function getLastUsedAt(): int
    {
        return $this->channel->getLastUsedAt();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->channel->isBusy() || $this->leases->isBusy()) {
            $this->forceClose();

            return;
        }

        $this->closeDependents();
        $lock = $this->leases->acquireConnection();
        try {
            $this->channel->close();
        } catch (WorkerFailure) {
            $this->forceClose();

            return;
        } finally {
            $lock->release();
        }

        $this->onClose->complete();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    public function openBlob(
        string $table,
        string $column,
        int $rowId,
        string $database = 'main',
        SqliteBlobMode $mode = SqliteBlobMode::ReadOnly,
    ): SqliteBlobStream {
        return $this->openBlobStream($table, $column, $rowId, $database, $mode, false);
    }

    public function backup(string $destinationPath, string $database = 'main'): void
    {
        $this->copyDatabase('backup', $destinationPath, $database);
    }

    public function restore(string $sourcePath, string $database = 'main'): void
    {
        $this->copyDatabase('restore', $sourcePath, $database);
    }

    public function queryInTransaction(string $sql): SqliteResult
    {
        return $this->run($sql, [], false, true);
    }

    public function openBlobInTransaction(
        string $table,
        string $column,
        int $rowId,
        string $database,
        SqliteBlobMode $mode,
    ): SqliteBlobStream {
        return $this->openBlobStream($table, $column, $rowId, $database, $mode, true);
    }

    public function prepareInTransaction(string $sql, Transaction $transaction): SqliteStatement
    {
        return $this->prepareStatement($sql, $transaction);
    }

    /**
     * @param array<array-key, SqliteParameterValue> $params
     */
    public function executeInTransaction(string $sql, #[\SensitiveParameter] array $params): SqliteResult
    {
        return $this->run($sql, $params, true, true);
    }

    /**
     * @param array<array-key, SqliteParameterValue> $params
     */
    public function executeStatement(int $statementId, string $sql, #[\SensitiveParameter] array $params, bool $transactional, Statement $statement): SqliteResult
    {
        $this->assertOpen();
        self::validateParameterValues($params);
        $lock = $this->acquire($transactional);

        if ($statement->isClosed()) {
            $this->releaseAcquired($lock, $transactional);

            throw new SqliteException('The SQLite statement is closed');
        }

        try {
            $value = $this->requestResultPayload('executeStatement', $sql, ['statement_id' => $statementId, 'params' => $params]);
        } catch (\Throwable $exception) {
            $this->releaseAcquired($lock, $transactional);
            throw $exception;
        }

        return $this->createResult($value, $sql, $lock, $transactional);
    }

    public function executeControl(string $sql): void
    {
        $this->assertCurrentTaskHoldsNoTransactionLease();
        $this->leases->awaitTransactionIdle();
        $value = $this->requestResultPayload('execute', $sql, ['sql' => $sql, 'params' => []]);
        if ($value['result_id'] !== null) {
            $this->closeResult($value['result_id'], $sql);
        }
    }

    /**
     * @throws SqliteTransactionError If the current task still holds unread results or BLOB streams of the transaction
     */
    public function assertCurrentTaskHoldsNoTransactionLease(): void
    {
        if ($this->leases->currentTaskHoldsTransactionLease()) {
            throw new SqliteTransactionError('Close the unread results and BLOB streams of the transaction first');
        }
    }

    public function releaseTransaction(Transaction $transaction): void
    {
        $active = $this->activeTransaction?->get();
        if ($active !== null && $active !== $transaction) {
            return;
        }

        $this->releaseTransactionLock();
    }

    public function releaseTransactionLock(): void
    {
        $this->activeTransaction = null;
        $this->leases->releaseTransactionLock();
    }

    public function closeStatement(int $statementId, string $sql): void
    {
        if ($this->closed || $this->channel->isClosed()) {
            return;
        }

        try {
            $this->requestVoid('closeStatement', $sql, ['statement_id' => $statementId]);
        } catch (SqliteConnectionException) {
            // Closing a statement on a dead connection is a no-op.
        }
    }

    private function copyDatabase(string $operation, string $path, string $database): void
    {
        Path::validate($path);
        if ($path === ':memory:') {
            throw new \InvalidArgumentException('Backup and restore require a file path');
        }

        $this->assertOpen();
        $lock = $this->acquireConnectionLock();

        try {
            $this->requestVoid($operation, '', ['path' => Path::resolve($path), 'database' => $database]);
        } finally {
            $lock->release();
        }
    }

    private function openBlobStream(
        string $table,
        string $column,
        int $rowId,
        string $database,
        SqliteBlobMode $mode,
        bool $transactional,
    ): SqliteBlobStream {
        $this->assertOpen();
        $lock = $this->acquire($transactional);

        try {
            $value = $this->requestOpenBlobPayload('openBlob', '', [
                'table' => $table,
                'column' => $column,
                'row_id' => $rowId,
                'database' => $database,
                'mode' => $mode->name,
            ]);
        } catch (\Throwable $exception) {
            $this->releaseAcquired($lock, $transactional);
            throw $exception;
        }

        $lease = $this->leases->retain($lock, $transactional);
        $blobId = $value['blob_id'];

        $blob = new BlobStream(
            $value['length'],
            $mode,
            fn (int $length): string => $this->requestBlobBytes('readBlob', '', [
                'blob_id' => $blobId,
                'length' => $length,
            ]),
            function (string $bytes) use ($blobId): void {
                $this->requestVoid('writeBlob', '', [
                    'blob_id' => $blobId,
                    'bytes' => $bytes,
                ]);
            },
            function () use ($blobId, $lease): void {
                try {
                    if (!$this->closed && !$this->channel->isClosed()) {
                        $this->requestVoid('closeBlob', '', ['blob_id' => $blobId]);
                    }
                } catch (SqliteConnectionException) {
                    // Closing a BLOB on a dead connection is a no-op.
                } finally {
                    $lease->release();
                }
            },
        );
        $this->blobs[$blob] = true;
        if ($transactional) {
            $this->leases->trackTransactionResource($blob);
        }

        return $blob;
    }

    private function prepareStatement(string $sql, ?Transaction $transaction = null): SqliteStatement
    {
        $this->assertOpen();
        $lock = $this->acquire($transaction !== null);

        try {
            $statementId = $this->requestStatementId('prepare', $sql, ['sql' => $sql]);
        } finally {
            $this->releaseAcquired($lock, $transaction !== null);
        }

        $statement = new Statement($this, $statementId, $sql, $transaction);
        $this->statements[$statement] = true;

        return $statement;
    }

    /**
     * @param array<array-key, SqliteParameterValue> $params
     */
    private function run(string $sql, #[\SensitiveParameter] array $params, bool $bindParameters, bool $transactional): SqliteResult
    {
        $this->assertOpen();
        self::validateParameterValues($params);
        $lock = $this->acquire($transactional);

        try {
            $value = $this->requestResultPayload('execute', $sql, [
                'sql' => $sql,
                'params' => $params,
                'bind_parameters' => $bindParameters,
            ]);
        } catch (\Throwable $exception) {
            $this->releaseAcquired($lock, $transactional);
            throw $exception;
        }

        return $this->createResult($value, $sql, $lock, $transactional);
    }

    private function acquire(bool $transactional): ?Lock
    {
        if (!$transactional) {
            return $this->acquireConnectionLock();
        }

        $this->leases->acquireTransactionLease();

        return null;
    }

    private function releaseAcquired(?Lock $lock, bool $transactional): void
    {
        $this->leases->release($lock, $transactional);
    }

    /**
     * @param SqliteResultPayload $value
     */
    private function createResult(array $value, string $sql, ?Lock $lock, bool $transactional): SqliteResult
    {
        $lease = null;
        if ($value['exhausted']) {
            $this->leases->release($lock, $transactional);
        } else {
            $lease = $this->leases->retain($lock, $transactional);
        }

        $result = new Result(
            $value['rows'],
            $value['row_count'],
            $value['column_count'],
            $value['column_names'],
            $value['last_insert_id'],
            $value['result_id'],
            $value['exhausted'],
            fn (int $resultId): array => $this->requestBatchPayload('fetch', $sql, ['result_id' => $resultId]),
            fn (int $resultId): null => $this->closeResult($resultId, $sql),
            $lease,
        );
        $this->results[$result] = true;
        if ($transactional && $lease !== null) {
            $this->leases->trackTransactionResource($result);
        }

        return $result;
    }

    private function closeResult(int $resultId, string $sql): null
    {
        if ($this->closed || $this->channel->isClosed()) {
            return null;
        }

        try {
            $this->requestVoid('closeResult', $sql, ['result_id' => $resultId]);
        } catch (SqliteConnectionException) {
            // Closing a result on a dead connection is a no-op.
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requestVoid(string $operation, string $sql, #[\SensitiveParameter] array $data): void
    {
        try {
            WorkerResponse::void($this->channel->request($operation, $sql, $data));
        } catch (WorkerFailure $failure) {
            $this->fail($failure);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return SqliteResultPayload
     */
    private function requestResultPayload(string $operation, string $sql, #[\SensitiveParameter] array $data): array
    {
        try {
            return WorkerResponse::result($this->channel->request($operation, $sql, $data));
        } catch (WorkerFailure $failure) {
            $this->fail($failure);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return SqliteBatchPayload
     */
    private function requestBatchPayload(string $operation, string $sql, array $data): array
    {
        try {
            return WorkerResponse::batch($this->channel->request($operation, $sql, $data));
        } catch (WorkerFailure $failure) {
            $this->fail($failure);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{blob_id: int, length: int}
     */
    private function requestOpenBlobPayload(string $operation, string $sql, array $data): array
    {
        try {
            return WorkerResponse::openBlob($this->channel->request($operation, $sql, $data));
        } catch (WorkerFailure $failure) {
            $this->fail($failure);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requestStatementId(string $operation, string $sql, array $data): int
    {
        try {
            return WorkerResponse::statementId($this->channel->request($operation, $sql, $data));
        } catch (WorkerFailure $failure) {
            $this->fail($failure);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requestBlobBytes(string $operation, string $sql, array $data): string
    {
        try {
            return WorkerResponse::blobBytes($this->channel->request($operation, $sql, $data));
        } catch (WorkerFailure $failure) {
            $this->fail($failure);
        }
    }

    private function fail(WorkerFailure $failure): never
    {
        $this->closed = true;
        $this->forceClose();

        throw new SqliteConnectionException($failure->getMessage(), previous: $failure->getPrevious());
    }

    private function acquireConnectionLock(): Lock
    {
        $lock = $this->leases->acquireConnection();
        if ($this->closed) {
            $lock->release();

            throw new SqliteConnectionException('The SQLite connection is closed');
        }

        return $lock;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new SqliteConnectionException('The SQLite connection is closed');
        }
    }

    private function forceClose(): void
    {
        $this->closeDependents();

        $transaction = $this->activeTransaction?->get();
        $this->activeTransaction = null;
        $transaction?->releaseOnConnectionClose();

        $this->leases->reset();

        $this->channel->kill();

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    private function closeDependents(): void
    {
        foreach ($this->results as $result => $_) {
            try {
                $result->closeOnConnectionClose();
            } catch (\Throwable) {
            }
        }
        foreach ($this->blobs as $blob => $_) {
            try {
                $blob->closeOnConnectionClose();
            } catch (\Throwable) {
            }
        }
        foreach ($this->statements as $statement => $_) {
            try {
                $statement->close();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param DeferredFuture<null> $onClose
     */
    private static function closeChannel(WorkerChannel $channel, DeferredFuture $onClose): void
    {
        try {
            $channel->close();
        } catch (WorkerFailure) {
            $channel->kill();
        } finally {
            if (!$onClose->isComplete()) {
                $onClose->complete();
            }
        }
    }

    /**
     * @param array<array-key, SqliteParameterValue> $params
     */
    private static function validateParameterValues(#[\SensitiveParameter] array $params): void
    {
        foreach ($params as $value) {
            if ($value !== null && !\is_bool($value) && !\is_int($value) && !\is_float($value) && !\is_string($value) && !$value instanceof SqliteBlob) {
                throw new \TypeError('SQLite parameters must be null, bool, int, float, string, or SqliteBlob');
            }
        }
    }
}
