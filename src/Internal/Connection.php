<?php

declare(strict_types=1);

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
use Fabpot\Amp\Sqlite\SqliteQueryError;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;

final class Connection implements SqliteConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly LocalMutex $mutex;
    private readonly DeferredFuture $onClose;
    private SqliteTransactionMode $transactionMode;
    private bool $closed = false;
    private bool $operationActive = false;
    private int $activeLeases = 0;
    private int $lastUsedAt;
    private int $nextRequestId = 1;
    private ?Transaction $activeTransaction = null;
    private ?Lock $transactionLock = null;
    private ?DeferredFuture $transactionBusy = null;

    public function __construct(
        private readonly SqliteConfig $config,
        private readonly Context $context,
    ) {
        $this->mutex = new LocalMutex();
        $this->onClose = new DeferredFuture();
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

    public function execute(string $sql, array $params = []): SqliteResult
    {
        return $this->run($sql, $params, true, false);
    }

    public function beginTransaction(): SqliteTransaction
    {
        if ($this->activeTransaction !== null) {
            throw new SqliteTransactionError('A transaction is already active');
        }

        $this->transactionLock = $this->mutex->acquire();
        try {
            $this->executeControl('BEGIN ' . $this->transactionMode->toSql());
        } catch (\Throwable $exception) {
            $this->transactionLock->release();
            $this->transactionLock = null;
            throw $exception;
        }

        return $this->activeTransaction = new Transaction($this, $this->transactionMode);
    }

    public function getConfig(): SqliteConfig
    {
        return $this->config;
    }

    public function getTransactionIsolation(): SqlTransactionIsolation
    {
        return $this->transactionMode;
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        if (!$isolation instanceof SqliteTransactionMode) {
            throw new \TypeError('SQLite connections only accept SqliteTransactionMode');
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

        if ($this->operationActive || $this->activeLeases > 0 || $this->activeTransaction !== null) {
            $this->forceClose();

            return;
        }

        $lock = $this->mutex->acquire();
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

    public function executeInTransaction(string $sql, array $params): SqliteResult
    {
        return $this->run($sql, $params, true, true);
    }

    public function executeStatement(int $statementId, string $sql, array $params, bool $transactional): SqliteResult
    {
        $this->assertOpen();
        self::validateParameterValues($params);
        $lock = $this->acquire($transactional);

        try {
            $value = $this->request('executeStatement', $sql, ['statement_id' => $statementId, 'params' => $params]);
        } catch (\Throwable $exception) {
            $lock?->release();
            throw $exception;
        }

        return $this->createResult($value, $sql, $lock, $transactional);
    }

    public function executeControl(string $sql): void
    {
        $this->awaitTransactionResource();
        $value = $this->request('execute', $sql, [
            'sql' => $sql,
            'params' => [],
            'bind_parameters' => true,
        ]);
        if ($value['result_id'] !== null) {
            $this->requestResult('closeResult', $value['result_id'], $sql);
        }
    }

    public function releaseTransaction(Transaction $transaction): void
    {
        if ($this->activeTransaction !== $transaction) {
            return;
        }

        $this->activeTransaction = null;
        $this->transactionLock?->release();
        $this->transactionLock = null;
    }

    public function closeStatement(int $statementId, string $sql, bool $transactional): void
    {
        if ($this->closed) {
            return;
        }

        if ($transactional && $this->transactionLock !== null) {
            $this->awaitTransactionResource();
            $lock = null;
        } else {
            $lock = $this->mutex->acquire();
        }

        try {
            $this->request('closeStatement', $sql, ['statement_id' => $statementId]);
        } finally {
            $lock?->release();
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
            $value = $this->request('openBlob', '', [
                'table' => $table,
                'column' => $column,
                'row_id' => $rowId,
                'database' => $database,
                'mode' => $mode->name,
            ]);
        } catch (\Throwable $exception) {
            $lock?->release();
            throw $exception;
        }

        ++$this->activeLeases;
        if ($transactional) {
            $this->transactionBusy = new DeferredFuture();
        }

        $released = false;
        $release = function () use (&$released, $transactional, $lock): void {
            if ($released) {
                return;
            }

            $released = true;
            --$this->activeLeases;
            $lock?->release();
            if ($transactional) {
                $this->transactionBusy?->complete();
                $this->transactionBusy = null;
            }
        };
        $blobId = $value['blob_id'];

        return new BlobStream(
            $value['length'],
            $mode,
            fn (int $length): string => $this->request('readBlob', '', [
                'blob_id' => $blobId,
                'length' => $length,
            ])['bytes'],
            fn (string $bytes): mixed => $this->request('writeBlob', '', [
                'blob_id' => $blobId,
                'bytes' => $bytes,
            ]),
            function () use ($blobId, $release): void {
                try {
                    if (!$this->closed) {
                        $this->request('closeBlob', '', ['blob_id' => $blobId]);
                    }
                } finally {
                    $release();
                }
            },
        );
    }

    private function prepareStatement(string $sql, ?Transaction $transaction = null): SqliteStatement
    {
        $this->assertOpen();
        $lock = $this->acquire($transaction !== null);

        try {
            $value = $this->request('prepare', $sql, ['sql' => $sql]);
        } finally {
            $lock?->release();
        }

        return new Statement($this, $value['statement_id'], $sql, $transaction);
    }

    private function run(string $sql, array $params, bool $bindParameters, bool $transactional): SqliteResult
    {
        $this->assertOpen();
        self::validateParameterValues($params);
        $lock = $this->acquire($transactional);

        try {
            $value = $this->request('execute', $sql, [
                'sql' => $sql,
                'params' => $params,
                'bind_parameters' => $bindParameters,
            ]);
        } catch (\Throwable $exception) {
            $lock?->release();
            throw $exception;
        }

        return $this->createResult($value, $sql, $lock, $transactional);
    }

    private function acquire(bool $transactional): ?Lock
    {
        if (!$transactional) {
            return $this->mutex->acquire();
        }

        if ($this->transactionLock === null) {
            throw new SqliteTransactionError('The transaction is no longer active');
        }

        $this->awaitTransactionResource();

        return null;
    }

    private function createResult(array $value, string $sql, ?Lock $lock, bool $transactional): SqliteResult
    {
        $onRelease = null;
        if (!$value['exhausted']) {
            ++$this->activeLeases;
            if ($transactional) {
                $this->transactionBusy = new DeferredFuture();
            }

            $released = false;
            $onRelease = function () use (&$released, $transactional): void {
                if ($released) {
                    return;
                }

                $released = true;
                --$this->activeLeases;
                if ($transactional) {
                    $this->transactionBusy?->complete();
                    $this->transactionBusy = null;
                }
            };
        }

        return new Result(
            $value['rows'],
            $value['row_count'],
            $value['column_count'],
            $value['last_insert_id'],
            $value['result_id'],
            $value['exhausted'],
            fn (int $resultId): array => $this->requestResult('fetch', $resultId, $sql),
            fn (int $resultId): mixed => $this->requestResult('closeResult', $resultId, $sql),
            $value['exhausted'] ? null : $lock,
            $onRelease,
        );
    }

    private function requestResult(string $operation, int $resultId, string $sql): mixed
    {
        if ($this->closed && $operation === 'closeResult') {
            return null;
        }

        return $this->request($operation, $sql, ['result_id' => $resultId]);
    }

    private function request(string $operation, string $sql, array $data): mixed
    {
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
        }

        $this->lastUsedAt = \time();
        $this->validateResponse($response, $id, $sql);

        return $response['value'];
    }

    private function validateResponse(mixed $response, int $id, string $sql): void
    {
        if (($response['id'] ?? null) !== $id) {
            $this->closed = true;
            $this->forceClose();

            throw new SqliteConnectionException('Received an invalid response from the SQLite child process');
        }

        if (isset($response['protocol_error'])) {
            $this->closed = true;
            $this->forceClose();

            throw new SqliteConnectionException($response['protocol_error']['message']);
        }

        if (isset($response['query_error'])) {
            throw new SqliteQueryError(
                $response['query_error']['message'],
                $sql,
                $response['query_error']['code'],
                $response['query_error']['extended_code'],
            );
        }
    }

    private function awaitTransactionResource(): void
    {
        while ($this->transactionBusy !== null) {
            $this->transactionBusy->getFuture()->await();
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new SqliteConnectionException('The SQLite connection is closed');
        }
    }

    private function forceClose(): void
    {
        $this->context->close();
        try {
            $this->context->join();
        } catch (\Throwable) {
        }

        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    private static function validateParameterValues(array $params): void
    {
        foreach ($params as $value) {
            if ($value !== null && !\is_bool($value) && !\is_int($value) && !\is_float($value) && !\is_string($value) && !$value instanceof SqliteBlob) {
                throw new \TypeError('SQLite parameters must be null, bool, int, float, string, or SqliteBlob');
            }
        }
    }
}
