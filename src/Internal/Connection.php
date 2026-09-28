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
use Amp\Parallel\Context\Context;
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
use Fabpot\Amp\Sqlite\SqliteQueryError;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;

/** @internal */
final class Connection implements SqliteConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly LocalMutex $mutex;
    private readonly LocalMutex $requestMutex;
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
    private bool $operationActive = false;
    private int $activeConnectionLocks = 0;
    private int $activeLeases = 0;
    private int $lastUsedAt;
    private int $nextRequestId = 1;
    /** @var \WeakReference<Transaction>|null */
    private ?\WeakReference $activeTransaction = null;
    private ?Lock $transactionLock = null;
    private int $transactionLeases = 0;
    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $transactionIdle = null;

    /**
     * @param Context<null, mixed, array<string, mixed>> $context
     */
    public function __construct(
        private readonly SqliteConfig $config,
        private readonly Context $context,
    ) {
        $this->mutex = new LocalMutex();
        $this->requestMutex = new LocalMutex();
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
        $this->lastUsedAt = \time();
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
        return $this->lastUsedAt;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->operationActive || $this->activeConnectionLocks > 0 || $this->activeLeases > 0 || $this->transactionLock !== null) {
            $this->forceClose();

            return;
        }

        $this->closeDependents();
        $lock = $this->mutex->acquire();
        $requestLock = $this->requestMutex->acquire();
        try {
            if (!$this->context->isClosed()) {
                $id = $this->nextRequestId++;
                $this->context->send(['id' => $id, 'operation' => 'close']);
                $this->context->receive();
                $this->context->join();
            }
        } catch (\Throwable) {
            $this->forceClose();

            return;
        } finally {
            $requestLock->release();
            $lock->release();
        }

        $this->context->close();
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
        if ($this->closed || $this->context->isClosed()) {
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

        $released = false;
        $release = function () use (&$released, $transactional, $lock): void {
            if ($released) {
                return;
            }

            $released = true;
            if ($this->activeLeases > 0) {
                --$this->activeLeases;
            }
            $lock?->release();
            if ($transactional) {
                $this->releaseTransactionLease();
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
                    if (!$this->closed && !$this->context->isClosed()) {
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
            $value = $this->requestStatementPayload('prepare', $sql, ['sql' => $sql]);
        } finally {
            $this->releaseAcquired($lock, $transaction !== null);
        }

        $statement = new Statement($this, $value['statement_id'], $sql, $transaction);
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

            $released = false;
            $onRelease = function () use (&$released, $transactional): void {
                if ($released) {
                    return;
                }

                $released = true;
                if ($this->activeLeases > 0) {
                    --$this->activeLeases;
                }
                if ($transactional) {
                    $this->releaseTransactionLease();
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
        if ($this->closed || $this->context->isClosed()) {
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
        if ($this->request($operation, $sql, $data) !== null) {
            $this->invalidResponse();
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return SqliteResultPayload
     */
    private function requestResultPayload(string $operation, string $sql, #[\SensitiveParameter] array $data): array
    {
        $value = $this->request($operation, $sql, $data);
        if (!\is_array($value)
            || \count($value) !== 7
            || !\array_key_exists('result_id', $value)
            || ($value['result_id'] !== null && !\is_int($value['result_id']))
            || !self::isRowList($value['rows'] ?? null)
            || !\is_bool($value['exhausted'] ?? null)
            || !\array_key_exists('row_count', $value)
            || ($value['row_count'] !== null && !\is_int($value['row_count']))
            || !\array_key_exists('column_count', $value)
            || ($value['column_count'] !== null && !\is_int($value['column_count']))
            || !\array_key_exists('column_names', $value)
            || !self::isStringListOrNull($value['column_names'])
            || !\array_key_exists('last_insert_id', $value)
            || ($value['last_insert_id'] !== null && !\is_int($value['last_insert_id']))
        ) {
            $this->invalidResponse();
        }

        /** @var list<string>|null $columnNames */
        $columnNames = $value['column_names'];
        if ($value['result_id'] === null) {
            if ($value['rows'] !== []
                || !$value['exhausted']
                || $value['row_count'] === null
                || $value['row_count'] < 0
                || $value['column_count'] !== null
                || $columnNames !== null
            ) {
                $this->invalidResponse();
            }
        } elseif ($value['result_id'] < 1
            || $value['row_count'] !== null
            || $value['column_count'] === null
            || $value['column_count'] < 1
            || $columnNames === null
            || \count($columnNames) !== $value['column_count']
        ) {
            $this->invalidResponse();
        }

        /** @var SqliteResultPayload $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return SqliteBatchPayload
     */
    private function requestBatchPayload(string $operation, string $sql, array $data): array
    {
        $value = $this->request($operation, $sql, $data);
        if (!\is_array($value) || \count($value) !== 2 || !self::isRowList($value['rows'] ?? null) || !\is_bool($value['exhausted'] ?? null)) {
            $this->invalidResponse();
        }

        /** @var SqliteBatchPayload $value */
        if (!$value['exhausted'] && $value['rows'] === []) {
            $this->invalidResponse();
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{blob_id: int, length: int}
     */
    private function requestOpenBlobPayload(string $operation, string $sql, array $data): array
    {
        $value = $this->request($operation, $sql, $data);
        if (!\is_array($value) || \count($value) !== 2 || !\is_int($value['blob_id'] ?? null) || $value['blob_id'] < 1 || !\is_int($value['length'] ?? null) || $value['length'] < 0) {
            $this->invalidResponse();
        }

        /** @var array{blob_id: int, length: int} $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{statement_id: int}
     */
    private function requestStatementPayload(string $operation, string $sql, array $data): array
    {
        $value = $this->request($operation, $sql, $data);
        if (!\is_array($value) || \count($value) !== 1 || !\is_int($value['statement_id'] ?? null) || $value['statement_id'] < 1) {
            $this->invalidResponse();
        }

        /** @var array{statement_id: int} $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requestBlobBytes(string $operation, string $sql, array $data): string
    {
        $value = $this->request($operation, $sql, $data);
        if (!\is_array($value) || \count($value) !== 1 || !\is_string($value['bytes'] ?? null)) {
            $this->invalidResponse();
        }

        /** @var array{bytes: string} $value */
        return $value['bytes'];
    }

    private static function isRowList(mixed $value): bool
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            return false;
        }

        foreach ($value as $row) {
            if (!\is_array($row) || !\array_all($row, self::isRowValue(...))) {
                return false;
            }
        }

        return true;
    }

    private static function isRowValue(mixed $value): bool
    {
        return $value === null
            || \is_int($value)
            || \is_float($value)
            || \is_string($value)
            || $value instanceof SqliteBlob;
    }

    private static function isStringListOrNull(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (!\is_array($value) || !\array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!\is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function request(string $operation, string $sql, #[\SensitiveParameter] array $data): mixed
    {
        $lock = $this->requestMutex->acquire();
        $id = $this->nextRequestId++;
        $this->operationActive = true;

        try {
            $this->context->send(['id' => $id, 'operation' => $operation, ...$data]);
            $response = $this->context->receive();
        } catch (\Throwable $exception) {
            $this->closed = true;
            $this->forceClose();

            throw new SqliteConnectionException('The SQLite child process stopped unexpectedly', previous: $exception);
        } finally {
            $this->operationActive = false;
            $lock->release();
        }

        $this->lastUsedAt = \time();
        $response = $this->validateResponse($response, $id, $sql);

        return $response['value'];
    }

    /**
     * @return array{id: int, value: mixed}
     */
    private function validateResponse(mixed $response, int $id, string $sql): array
    {
        if (!\is_array($response) || \count($response) !== 2 || ($response['id'] ?? null) !== $id) {
            $this->invalidResponse();
        }

        if (\array_key_exists('protocol_error', $response)) {
            $error = $this->validateProtocolError($response['protocol_error']);
            $this->closed = true;
            $this->forceClose();

            throw new SqliteConnectionException($error['message']);
        }

        if (\array_key_exists('query_error', $response)) {
            $error = $this->validateQueryError($response['query_error']);

            throw new SqliteQueryError(
                $error['message'],
                $sql,
                $error['code'],
                $error['extended_code'],
            );
        }

        if (\array_key_exists('operation_error', $response)) {
            $error = $this->validateOperationError($response['operation_error']);

            throw new SqliteException($error['message'], $error['code'] ?? 0);
        }

        if (!\array_key_exists('value', $response)) {
            $this->invalidResponse();
        }

        /** @var array{id: int, value: mixed} */
        return $response;
    }

    /**
     * @return array{message: string}
     */
    private function validateProtocolError(mixed $error): array
    {
        if (!\is_array($error) || \count($error) !== 1 || !\is_string($error['message'] ?? null)) {
            $this->invalidResponse();
        }

        /** @var array{message: string} $error */
        return $error;
    }

    /**
     * @return array{message: string, code: int|null, extended_code: int|null}
     */
    private function validateQueryError(mixed $error): array
    {
        if (!\is_array($error)
            || \count($error) !== 3
            || !\is_string($error['message'] ?? null)
            || !\array_key_exists('code', $error)
            || ($error['code'] !== null && !\is_int($error['code']))
            || !\array_key_exists('extended_code', $error)
            || ($error['extended_code'] !== null && !\is_int($error['extended_code']))
        ) {
            $this->invalidResponse();
        }

        /** @var array{message: string, code: int|null, extended_code: int|null} $error */
        return $error;
    }

    /**
     * @return array{message: string, code: int|null}
     */
    private function validateOperationError(mixed $error): array
    {
        if (!\is_array($error)
            || \count($error) !== 2
            || !\is_string($error['message'] ?? null)
            || !\array_key_exists('code', $error)
            || ($error['code'] !== null && !\is_int($error['code']))
        ) {
            $this->invalidResponse();
        }

        /** @var array{message: string, code: int|null} $error */
        return $error;
    }

    private function invalidResponse(): never
    {
        $this->closed = true;
        $this->forceClose();

        throw new SqliteConnectionException('Received an invalid response from the SQLite child process');
    }

    private function awaitTransactionResource(): void
    {
        while ($this->transactionLeases > 0) {
            ($this->transactionIdle ??= new DeferredFuture())->getFuture()->await();
        }
    }

    private function releaseTransactionLease(): void
    {
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
        $this->transactionIdle?->complete();
        $this->transactionIdle = null;
        $this->activeLeases = 0;

        if (!$this->context->isClosed()) {
            $this->context->close();
            try {
                $this->context->join();
            } catch (\Throwable) {
            }
        }

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    private function closeDependents(): void
    {
        foreach ($this->results as $result => $_) {
            try {
                $result->close();
            } catch (\Throwable) {
            }
        }
        foreach ($this->blobs as $blob => $_) {
            try {
                $blob->close();
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
