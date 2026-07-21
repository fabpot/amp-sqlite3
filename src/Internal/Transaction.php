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
use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteBlobStream;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;

/** @internal */
final class Transaction implements SqliteTransaction
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $onCommit;
    private readonly DeferredFuture $onRollback;
    private readonly DeferredFuture $onClose;
    private readonly LocalMutex $stateMutex;
    private bool $active = true;
    private int $nextSavepointId = 1;
    private ?Transaction $activeNested = null;
    private ?DeferredFuture $nestedBusy = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly SqliteTransactionMode $mode,
        private readonly ?self $parent = null,
        private readonly ?string $savepoint = null,
    ) {
        $this->onCommit = new DeferredFuture();
        $this->onRollback = new DeferredFuture();
        $this->onClose = new DeferredFuture();
        $this->stateMutex = new LocalMutex();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function query(string $sql): SqliteResult
    {
        $lock = $this->acquireOperation();

        try {
            return $this->connection->queryInTransaction($sql, $this);
        } finally {
            $lock->release();
        }
    }

    public function openBlob(
        string $table,
        string $column,
        int $rowId,
        string $database = 'main',
        SqliteBlobMode $mode = SqliteBlobMode::ReadOnly,
    ): SqliteBlobStream {
        $lock = $this->acquireOperation();

        try {
            return $this->connection->openBlobInTransaction($table, $column, $rowId, $database, $mode, $this);
        } finally {
            $lock->release();
        }
    }

    public function prepare(string $sql): SqliteStatement
    {
        $lock = $this->acquireOperation();

        try {
            return $this->connection->prepareInTransaction($sql, $this);
        } finally {
            $lock->release();
        }
    }

    public function execute(string $sql, #[\SensitiveParameter] array $params = []): SqliteResult
    {
        $lock = $this->acquireOperation();

        try {
            return $this->connection->executeInTransaction($sql, $params, $this);
        } finally {
            $lock->release();
        }
    }

    public function beginTransaction(): SqliteTransaction
    {
        $lock = $this->stateMutex->acquire();

        try {
            if (!$this->isActive()) {
                throw new SqliteTransactionError('The transaction has been committed or rolled back');
            }
            if ($this->activeNested !== null && $this->activeNested->isActive()) {
                throw new SqliteTransactionError('The nested transaction is still active');
            }

            $savepoint = 'amp_sqlite_' . $this->nextSavepointId++;
            $this->connection->executeControl("SAVEPOINT {$savepoint}");
            $this->nestedBusy = new DeferredFuture();
            $transaction = new self($this->connection, $this->mode, $this, $savepoint);
            $this->activeNested = $transaction;

            return $transaction;
        } finally {
            $lock->release();
        }
    }

    public function getIsolation(): SqliteTransactionMode
    {
        return $this->mode;
    }

    public function isActive(): bool
    {
        return $this->active && !$this->connection->isClosed();
    }

    public function getSavepointIdentifier(): ?string
    {
        return $this->savepoint;
    }

    public function commit(): void
    {
        $lock = $this->stateMutex->acquire();

        try {
            $this->assertNoActiveNestedTransaction();
            $this->assertActive();
            $this->connection->executeControl($this->savepoint === null ? 'COMMIT' : "RELEASE SAVEPOINT {$this->savepoint}");
            $this->active = false;
            $this->parent?->releaseNested($this);
            if ($this->parent === null) {
                $this->onCommit->complete();
                $this->connection->releaseTransaction($this);
            } else {
                $onCommit = $this->onCommit;
                $this->parent->onCommit(static fn () => $onCommit->isComplete() || $onCommit->complete());
                $onRollback = $this->onRollback;
                $this->parent->onRollback(static fn () => $onRollback->isComplete() || $onRollback->complete());
            }
            $this->onClose->complete();
        } finally {
            $lock->release();
        }
    }

    public function rollback(): void
    {
        $lock = $this->stateMutex->acquire();

        try {
            $this->assertNoActiveNestedTransaction();
            $this->assertActive();
            $this->rollbackActive();
        } finally {
            $lock->release();
        }
    }

    public function onCommit(\Closure $onCommit): void
    {
        $this->onCommit->getFuture()->finally($onCommit);
    }

    public function onRollback(\Closure $onRollback): void
    {
        $this->onRollback->getFuture()->finally($onRollback);
    }

    public function getLastUsedAt(): int
    {
        return $this->connection->getLastUsedAt();
    }

    public function close(): void
    {
        $lock = $this->stateMutex->acquire();

        try {
            if (!$this->active) {
                return;
            }

            if ($this->connection->isClosed()) {
                $this->active = false;
                $this->onRollback->complete();
                $this->onClose->complete();

                return;
            }

            $this->activeNested?->close();
            $this->rollbackActive();
        } finally {
            $lock->release();
        }
    }

    public function isClosed(): bool
    {
        return !$this->isActive();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    public function releaseOnConnectionClose(): void
    {
        $nested = $this->activeNested;
        if ($this->active) {
            $this->active = false;
            $this->parent?->releaseNested($this);
            $this->onRollback->complete();
            $this->onClose->complete();
        }

        $nested?->releaseOnConnectionClose();
    }

    private function releaseNested(self $transaction): void
    {
        if ($this->activeNested === $transaction) {
            $this->activeNested = null;
            $this->nestedBusy?->complete();
            $this->nestedBusy = null;
        }
    }

    private function rollbackActive(): void
    {
        if ($this->savepoint === null) {
            $this->connection->executeControl('ROLLBACK');
        } else {
            $this->connection->executeControl("ROLLBACK TO SAVEPOINT {$this->savepoint}");
            $this->connection->executeControl("RELEASE SAVEPOINT {$this->savepoint}");
        }

        $this->active = false;
        $this->parent?->releaseNested($this);
        $this->onRollback->complete();
        $this->onClose->complete();
        if ($this->savepoint === null) {
            $this->connection->releaseTransaction($this);
        }
    }

    public function acquireOperation(): Lock
    {
        while (true) {
            $nestedBusy = $this->nestedBusy;
            if ($nestedBusy !== null) {
                $nestedBusy->getFuture()->await();

                continue;
            }

            $lock = $this->stateMutex->acquire();
            $nestedBusy = $this->nestedBusy;
            if ($nestedBusy !== null) {
                $lock->release();

                continue;
            }

            if (!$this->isActive()) {
                $lock->release();

                throw new SqliteTransactionError('The transaction has been committed or rolled back');
            }

            return $lock;
        }
    }

    private function assertActive(): void
    {
        if (!$this->isActive()) {
            throw new SqliteTransactionError('The transaction has been committed or rolled back');
        }
    }

    private function assertNoActiveNestedTransaction(): void
    {
        if ($this->activeNested !== null && $this->activeNested->isActive()) {
            throw new SqliteTransactionError('The nested transaction is still active');
        }
    }
}
