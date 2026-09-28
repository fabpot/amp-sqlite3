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
use Amp\Sync\LocalMutex;
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
use Revolt\EventLoop\FiberLocal;

/** @internal */
final class Connection implements SqliteConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly LocalMutex $mutex;
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
    private int $activeConnectionLocks = 0;
    private int $activeLeases = 0;
    /** @var \WeakReference<Transaction>|null */
    private ?\WeakReference $activeTransaction = null;
    private ?Lock $transactionLock = null;
    private int $transactionLeases = 0;
    /** @var \WeakMap<\stdClass, int> */
    private \WeakMap $transactionLeaseOwners;
    /** @var FiberLocal<\stdClass> */
    private readonly FiberLocal $task;
    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $transactionIdle = null;

    public function __construct(
        private readonly SqliteConfig $config,
        private readonly WorkerChannel $channel,
    ) {
        $this->mutex = new LocalMutex();
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
        /** @var \WeakMap<\stdClass, int> $transactionLeaseOwners */
        $transactionLeaseOwners = new \WeakMap();
        $this->transactionLeaseOwners = $transactionLeaseOwners;
        $this->task = new FiberLocal(static fn (): \stdClass => new \stdClass());
        $this->transactionMode = $config->getTransactionMode();
    }

    public function __destruct()
    {
        $this->close();
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
        $lock = $this->acquireConnectionLock();

        try {
            $this->executeControl('BEGIN ' . $this->transactionMode->toSql());
        } catch (\Throwable $exception) {
            $lock->release();
            throw $exception;
        }

        $this->transactionLock = $lock;
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

        if ($this->channel->isBusy() || $this->activeConnectionLocks > 0 || $this->activeLeases > 0 || $this->transactionLock !== null) {
            $this->forceClose();

            return;
        }

        $this->closeDependents();
        $lock = $this->mutex->acquire();
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

    public function queryInTransaction(string $sql, Transaction $transaction): SqliteResult
    {
        return $this->run($sql, [], false, $transaction);
    }

    public function openBlobInTransaction(
        string $table,
        string $column,
        int $rowId,
        string $database,
        SqliteBlobMode $mode,
        Transaction $transaction,
    ): SqliteBlobStream {
        return $this->openBlobStream($table, $column, $rowId, $database, $mode, $transaction);
    }

    public function prepareInTransaction(string $sql, Transaction $transaction): SqliteStatement
    {
        return $this->prepareStatement($sql, $transaction);
    }

    /**
     * @param array<array-key, SqliteParameterValue> $params
     */
    public function executeInTransaction(string $sql, #[\SensitiveParameter] array $params, Transaction $transaction): SqliteResult
    {
        return $this->run($sql, $params, true, $transaction);
    }

    /**
     * @param array<array-key, SqliteParameterValue> $params
     */
    public function executeStatement(int $statementId, string $sql, #[\SensitiveParameter] array $params, ?Transaction $transaction, Statement $statement): SqliteResult
    {
        $this->assertOpen();
        self::validateParameterValues($params);
        $transactional = $transaction !== null;
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

        return $this->createResult($value, $sql, $lock, $transaction);
    }

    public function executeControl(string $sql): void
    {
        if (isset($this->transactionLeaseOwners[$this->task->get()])) {
            throw new SqliteTransactionError('Close the unread results and BLOB streams of the transaction first');
        }

        $this->awaitTransactionResource();
        $value = $this->requestResultPayload('execute', $sql, ['sql' => $sql, 'params' => []]);
        if ($value['result_id'] !== null) {
            $this->closeResult($value['result_id'], $sql);
        }
    }

    public function releaseTransaction(Transaction $transaction): void
    {
        $active = $this->activeTransaction?->get();
        if ($active !== null && $active !== $transaction) {
            return;
        }

        $this->activeTransaction = null;
        $this->transactionLock?->release();
        $this->transactionLock = null;
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
        Transaction|false $transaction,
    ): SqliteBlobStream {
        $this->assertOpen();
        $transactional = $transaction !== false;
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

        ++$this->activeLeases;
        $owner = $transactional ? $this->trackTransactionLeaseOwner() : null;

        $released = false;
        $release = function () use (&$released, $transactional, $lock, $owner): void {
            if ($released) {
                return;
            }

            $released = true;
            if ($this->activeLeases > 0) {
                --$this->activeLeases;
            }
            $lock?->release();
            if ($transactional) {
                $this->releaseTransactionLease($owner);
            }
        };
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
            function () use ($blobId, $release): void {
                try {
                    if (!$this->closed && !$this->channel->isClosed()) {
                        $this->requestVoid('closeBlob', '', ['blob_id' => $blobId]);
                    }
                } catch (SqliteConnectionException) {
                    // Closing a BLOB on a dead connection is a no-op.
                } finally {
                    $release();
                }
            },
            $transaction ?: null,
        );
        $this->blobs[$blob] = true;

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
    private function run(string $sql, #[\SensitiveParameter] array $params, bool $bindParameters, Transaction|false $transaction): SqliteResult
    {
        $this->assertOpen();
        self::validateParameterValues($params);
        $transactional = $transaction !== false;
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

        return $this->createResult($value, $sql, $lock, $transaction ?: null);
    }

    private function acquire(bool $transactional): ?Lock
    {
        if (!$transactional) {
            return $this->acquireConnectionLock();
        }

        $this->awaitTransactionResource();
        if ($this->closed || $this->transactionLock === null) {
            throw new SqliteTransactionError('The transaction is no longer active');
        }
        ++$this->transactionLeases;

        return null;
    }

    private function releaseAcquired(?Lock $lock, bool $transactional): void
    {
        $lock?->release();
        if ($transactional) {
            $this->releaseTransactionLease();
        }
    }

    /**
     * @param SqliteResultPayload $value
     */
    private function createResult(array $value, string $sql, ?Lock $lock, ?Transaction $transaction = null): SqliteResult
    {
        $transactional = $transaction !== null;
        $onRelease = null;
        if ($value['exhausted']) {
            $lock?->release();
            if ($transactional) {
                $this->releaseTransactionLease();
            }
        } else {
            ++$this->activeLeases;
            $owner = $transactional ? $this->trackTransactionLeaseOwner() : null;

            $released = false;
            $onRelease = function () use (&$released, $transactional, $owner): void {
                if ($released) {
                    return;
                }

                $released = true;
                if ($this->activeLeases > 0) {
                    --$this->activeLeases;
                }
                if ($transactional) {
                    $this->releaseTransactionLease($owner);
                }
            };
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
            $value['exhausted'] ? null : $lock,
            $onRelease,
            $value['exhausted'] ? null : $transaction,
        );
        $this->results[$result] = true;

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

    private function awaitTransactionResource(): void
    {
        while ($this->transactionLeases > 0) {
            ($this->transactionIdle ??= new DeferredFuture())->getFuture()->await();
        }
    }

    private function trackTransactionLeaseOwner(): \stdClass
    {
        $owner = $this->task->get();
        $this->transactionLeaseOwners[$owner] = ($this->transactionLeaseOwners[$owner] ?? 0) + 1;

        return $owner;
    }

    private function releaseTransactionLease(?\stdClass $owner = null): void
    {
        if ($owner !== null && isset($this->transactionLeaseOwners[$owner]) && --$this->transactionLeaseOwners[$owner] === 0) {
            unset($this->transactionLeaseOwners[$owner]);
        }

        if ($this->transactionLeases === 0) {
            return;
        }

        if (--$this->transactionLeases === 0) {
            $this->transactionIdle?->complete();
            $this->transactionIdle = null;
        }
    }

    private function acquireConnectionLock(): Lock
    {
        $lock = $this->mutex->acquire();
        if ($this->closed) {
            $lock->release();

            throw new SqliteConnectionException('The SQLite connection is closed');
        }

        ++$this->activeConnectionLocks;

        return new Lock(function () use ($lock): void {
            --$this->activeConnectionLocks;
            $lock->release();
        });
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

        $this->transactionLock?->release();
        $this->transactionLock = null;
        $this->transactionLeases = 0;
        $this->transactionLeaseOwners = new \WeakMap();
        $this->transactionIdle?->complete();
        $this->transactionIdle = null;
        $this->activeLeases = 0;

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
