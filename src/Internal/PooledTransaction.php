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

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteBlobStream;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use Revolt\EventLoop;

/** @internal */
final class PooledTransaction implements SqliteTransaction
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var object{count: int} */
    private readonly object $references;

    /**
     * @param \Closure():void $release
     */
    public function __construct(
        private readonly SqliteTransaction $transaction,
        \Closure $release,
    ) {
        $this->references = $references = new class {
            public int $count = 1;
        };
        $this->release = static function () use ($references, $release): void {
            if (--$references->count === 0) {
                $release();
            }
        };

        $this->transaction->onClose($this->release);
        if (!$this->transaction->isActive()) {
            $this->close();
        }
    }

    /** @var \Closure():void */
    private readonly \Closure $release;

    public function query(string $sql): SqliteResult
    {
        ++$this->references->count;

        try {
            $result = $this->transaction->query($sql);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }

        return new PooledResult($result, $this->release);
    }

    public function prepare(string $sql): SqliteStatement
    {
        ++$this->references->count;

        try {
            $statement = $this->transaction->prepare($sql);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }

        return new PooledStatement($statement, $this->release);
    }

    public function execute(string $sql, #[\SensitiveParameter] array $params = []): SqliteResult
    {
        ++$this->references->count;

        try {
            $result = $this->transaction->execute($sql, $params);
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }

        return new PooledResult($result, $this->release);
    }

    public function beginTransaction(): SqliteTransaction
    {
        ++$this->references->count;

        try {
            $transaction = $this->transaction->beginTransaction();
        } catch (\Throwable $exception) {
            EventLoop::queue($this->release);
            throw $exception;
        }

        return new self($transaction, $this->release);
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

    public function isActive(): bool
    {
        return $this->transaction->isActive();
    }

    public function commit(): void
    {
        $this->transaction->commit();
    }

    public function rollback(): void
    {
        $this->transaction->rollback();
    }

    public function onCommit(\Closure $onCommit): void
    {
        $this->transaction->onCommit($onCommit);
    }

    public function onRollback(\Closure $onRollback): void
    {
        $this->transaction->onRollback($onRollback);
    }

    public function getSavepointIdentifier(): ?string
    {
        return $this->transaction->getSavepointIdentifier();
    }

    public function getLastUsedAt(): int
    {
        return $this->transaction->getLastUsedAt();
    }

    public function close(): void
    {
        $this->transaction->close();
    }

    public function isClosed(): bool
    {
        return $this->transaction->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->transaction->onClose($onClose);
    }
}
