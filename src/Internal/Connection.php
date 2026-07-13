<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parallel\Context\Context;
use Amp\Sql\SqlTransactionIsolation;
use Amp\Sync\LocalMutex;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnection;
use Fabpot\Amp\Sqlite\SqliteConnectionException;
use Fabpot\Amp\Sqlite\SqliteQueryError;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;

final class Connection implements SqliteConnection
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly LocalMutex $mutex;
    private readonly DeferredFuture $onClose;
    private SqliteTransactionMode $transactionMode;
    private bool $closed = false;
    private int $lastUsedAt;
    private int $nextRequestId = 1;

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
        return $this->run($sql, [], false);
    }

    public function prepare(string $sql): SqliteStatement
    {
        throw new \Error('Prepared statements are not implemented yet');
    }

    public function execute(string $sql, array $params = []): SqliteResult
    {
        return $this->run($sql, $params);
    }

    public function beginTransaction(): SqliteTransaction
    {
        throw new \Error('Transactions are not implemented yet');
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
        $lock = $this->mutex->acquire();

        try {
            if (!$this->context->isClosed()) {
                $id = $this->nextRequestId++;
                $this->context->send(['id' => $id, 'operation' => 'close']);
                $this->context->receive();
            }
        } catch (\Throwable) {
        } finally {
            $this->context->close();
            $lock->release();
            $this->onClose->complete();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    private function run(string $sql, array $params = [], bool $allowPlaceholders = true): SqliteResult
    {
        if ($this->closed) {
            throw new SqliteConnectionException('The SQLite connection is closed');
        }

        self::validateParameters($sql, $params, $allowPlaceholders);

        $lock = $this->mutex->acquire();
        $id = $this->nextRequestId++;

        try {
            $this->context->send([
                'id' => $id,
                'operation' => 'execute',
                'sql' => $sql,
                'params' => $params,
            ]);
            $response = $this->context->receive();
        } catch (\Throwable $exception) {
            $lock->release();
            $this->closed = true;
            $this->context->close();
            $this->onClose->complete();

            throw new SqliteConnectionException('The SQLite child process stopped unexpectedly', previous: $exception);
        }

        $this->lastUsedAt = \time();

        try {
            $this->validateResponse($response, $id, $sql);
        } catch (\Throwable $exception) {
            $lock->release();
            throw $exception;
        }

        $value = $response['value'];

        return new Result(
            $value['rows'],
            $value['rowCount'],
            $value['columnCount'],
            $value['lastInsertId'],
            $value['resultId'],
            $value['exhausted'],
            fn (int $resultId): array => $this->requestResult('fetch', $resultId, $sql),
            fn (int $resultId): mixed => $this->requestResult('closeResult', $resultId, $sql),
            $value['exhausted'] ? null : $lock,
        );
    }

    private function requestResult(string $operation, int $resultId, string $sql): mixed
    {
        $id = $this->nextRequestId++;

        try {
            $this->context->send(['id' => $id, 'operation' => $operation, 'resultId' => $resultId]);
            $response = $this->context->receive();
        } catch (\Throwable $exception) {
            $this->closed = true;
            $this->context->close();
            $this->onClose->complete();

            throw new SqliteConnectionException('The SQLite child process stopped unexpectedly', previous: $exception);
        }

        $this->validateResponse($response, $id, $sql);

        return $response['value'];
    }

    private function validateResponse(mixed $response, int $id, string $sql): void
    {
        if (($response['id'] ?? null) !== $id) {
            $this->close();

            throw new SqliteConnectionException('Received an invalid response from the SQLite child process');
        }

        if (isset($response['error'])) {
            throw new SqliteQueryError(
                $response['error']['message'],
                $sql,
                $response['error']['code'],
                $response['error']['extendedCode'],
            );
        }
    }

    private static function validateParameters(string $sql, array $params, bool $allowPlaceholders): void
    {
        if (!SqlScanner::hasExecutableSql($sql)) {
            throw new SqliteQueryError('SQL must contain one statement', $sql);
        }

        $placeholders = SqlScanner::placeholders($sql);
        $styles = \array_unique(\array_column($placeholders, 'style'));
        if (\in_array('unsupported', $styles, true) || \count($styles) > 1) {
            throw new SqliteQueryError('Unsupported or mixed parameter placeholders', $sql);
        }

        if (!$allowPlaceholders && $placeholders !== []) {
            throw new SqliteQueryError('Placeholders are not allowed in direct queries', $sql);
        }

        $style = $styles[0] ?? null;
        if ($style === 'positional') {
            if (!\array_is_list($params) || \count($params) !== \count($placeholders)) {
                throw new SqliteQueryError('Positional parameters do not match the placeholders', $sql);
            }
        } elseif ($style === 'named') {
            $names = \array_values(\array_unique(\array_column($placeholders, 'name')));
            $keys = \array_keys($params);
            \sort($names);
            \sort($keys);
            if ($names !== $keys) {
                throw new SqliteQueryError('Named parameters do not match the placeholders', $sql);
            }
        } elseif ($params !== []) {
            throw new SqliteQueryError('Parameters were provided but the statement has no placeholders', $sql);
        }

        foreach ($params as $value) {
            if ($value !== null && !\is_bool($value) && !\is_int($value) && !\is_float($value) && !\is_string($value) && !$value instanceof \Fabpot\Amp\Sqlite\SqliteBlob) {
                throw new \TypeError('SQLite parameters must be null, bool, int, float, string, or SqliteBlob');
            }
        }
    }
}
