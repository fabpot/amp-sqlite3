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
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteBlobStream;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use Revolt\EventLoop;

/** @internal */
final class Transaction implements SqliteTransaction
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $onCommit;
    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $onRollback;
    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $onClose;
    private readonly LocalMutex $stateMutex;

    /** @var \WeakMap<Statement, true> */
    private \WeakMap $statements;

    private bool $active = true;
    private int $nextSavepointId = 1;
    /** @var \WeakReference<Transaction>|null */
    private ?\WeakReference $activeNested = null;
    /** @var DeferredFuture<null>|null */
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
        /** @var \WeakMap<Statement, true> $statements */
        $statements = new \WeakMap();
        $this->statements = $statements;
    }

    public function __destruct()
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        EventLoop::queue(
            self::rollbackDropped(...),
            $this->connection,
            $this->parent,
            $this->savepoint,
            $this->statements,
            $this->onRollback,
            $this->onClose,
        );
    }

    public function query(string $sql): SqliteResult
    {
        $lock = $this->acquireOperation();

        try {
            return $this->connection->queryInTransaction($sql);
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
            return $this->connection->openBlobInTransaction($table, $column, $rowId, $database, $mode);
        } finally {
            $lock->release();
        }
    }

    public function prepare(string $sql): SqliteStatement
    {
        $lock = $this->acquireOperation();

        try {
            $statement = $this->connection->prepareInTransaction($sql, $this);
            \assert($statement instanceof Statement);
            $this->statements[$statement] = true;

            return $statement;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<array-key, null|bool|int|float|string|SqliteBlob> $params
     */
    public function execute(string $sql, #[\SensitiveParameter] array $params = []): SqliteResult
    {
        $lock = $this->acquireOperation();

        try {
            return $this->connection->executeInTransaction($sql, $params);
        } finally {
            $lock->release();
        }
    }

    public function beginTransaction(): SqliteTransaction
    {
        $this->assertCurrentTaskHoldsNoLease();
        $lock = $this->stateMutex->acquire();

        try {
            if (!$this->isActive()) {
                throw new SqliteTransactionError('The transaction has been committed or rolled back');
            }
            $this->assertNoActiveNestedTransaction();
            $this->awaitDroppedNestedTransaction();

            $savepoint = 'amp_sqlite_' . $this->nextSavepointId++;
            $this->connection->executeControl("SAVEPOINT {$savepoint}");
            $this->nestedBusy = new DeferredFuture();
            $transaction = new self($this->connection, $this->mode, $this, $savepoint);
            $this->activeNested = \WeakReference::create($transaction);

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
        $this->assertCurrentTaskHoldsNoLease();
        $lock = $this->stateMutex->acquire();

        try {
            $this->assertNoActiveNestedTransaction();
            $this->awaitDroppedNestedTransaction();
            $this->assertActive();
            $this->connection->executeControl($this->savepoint === null ? 'COMMIT' : "RELEASE SAVEPOINT {$this->savepoint}");
            $this->active = false;
            self::closeStatements($this->statements);
            $this->parent?->releaseNested($this);
            if ($this->parent === null) {
                $this->onCommit->complete();
                $this->connection->releaseTransaction($this);
            } else {
                $onCommit = $this->onCommit;
                $this->parent->onCommit(static function () use ($onCommit): void {
                    if (!$onCommit->isComplete()) {
                        $onCommit->complete();
                    }
                });
                $onRollback = $this->onRollback;
                $this->parent->onRollback(static function () use ($onRollback): void {
                    if (!$onRollback->isComplete()) {
                        $onRollback->complete();
                    }
                });
            }
            $this->onClose->complete();
        } finally {
            $lock->release();
        }
    }

    public function rollback(): void
    {
        $this->assertCurrentTaskHoldsNoLease();
        $lock = $this->stateMutex->acquire();

        try {
            $this->assertNoActiveNestedTransaction();
            $this->awaitDroppedNestedTransaction();
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
        $this->assertCurrentTaskHoldsNoLease();
        $lock = $this->stateMutex->acquire();

        try {
            if (!$this->active) {
                return;
            }

            if ($this->connection->isClosed()) {
                $this->active = false;
                self::closeStatements($this->statements);
                $this->onRollback->complete();
                $this->onClose->complete();

                return;
            }

            $this->activeNested?->get()?->close();
            $this->awaitDroppedNestedTransaction();
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
        $nested = $this->activeNested?->get();
        if ($this->active) {
            $this->active = false;
            self::closeStatements($this->statements);
            $this->parent?->releaseNested($this);
            $this->onRollback->complete();
            $this->onClose->complete();
        }

        $nested?->releaseOnConnectionClose();
    }

    private function releaseNested(self $transaction): void
    {
        if ($this->activeNested?->get() === $transaction) {
            $this->clearNested();
        }
    }

    private function rollbackActive(): void
    {
        self::executeRollback($this->connection, $this->savepoint);

        $this->active = false;
        self::closeStatements($this->statements);
        $this->parent?->releaseNested($this);
        $this->onRollback->complete();
        $this->onClose->complete();
        if ($this->savepoint === null) {
            $this->connection->releaseTransaction($this);
        }
    }

    private static function executeRollback(Connection $connection, ?string $savepoint): void
    {
        if ($savepoint === null) {
            $connection->executeControl('ROLLBACK');
        } else {
            $connection->executeControl("ROLLBACK TO SAVEPOINT {$savepoint}");
            $connection->executeControl("RELEASE SAVEPOINT {$savepoint}");
        }
    }

    /**
     * @param \WeakMap<Statement, true> $statements
     */
    private static function closeStatements(\WeakMap $statements): void
    {
        foreach ($statements as $statement => $_) {
            try {
                $statement->close();
            } catch (\Throwable) {
            }
        }
    }

    private function clearNested(): void
    {
        $this->activeNested = null;
        $this->nestedBusy?->complete();
        $this->nestedBusy = null;
    }

    /**
     * @param \WeakMap<Statement, true> $statements
     * @param DeferredFuture<null> $onRollback
     * @param DeferredFuture<null> $onClose
     */
    private static function rollbackDropped(
        Connection $connection,
        ?self $parent,
        ?string $savepoint,
        \WeakMap $statements,
        DeferredFuture $onRollback,
        DeferredFuture $onClose,
    ): void {
        try {
            // A dropped parent rolls back the savepoint of its nested transaction too
            if (!$connection->isClosed() && ($parent === null || $parent->isActive())) {
                self::executeRollback($connection, $savepoint);
            }
        } catch (\Throwable) {
            // The transaction may still be open, so the connection must not be reused
            $connection->close();
        } finally {
            self::closeStatements($statements);
            $parent?->clearNested();
            $onRollback->complete();
            $onClose->complete();
            if ($savepoint === null) {
                $connection->releaseTransactionLock();
            }
        }
    }

    /**
     * Waits for the background rollback of a dropped nested transaction; callers reject an active one first.
     */
    private function awaitDroppedNestedTransaction(): void
    {
        while ($this->nestedBusy !== null) {
            $this->nestedBusy->getFuture()->await();
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

    private function assertCurrentTaskHoldsNoLease(): void
    {
        if ($this->isActive()) {
            $this->connection->assertCurrentTaskHoldsNoTransactionLease();
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
        if ($this->activeNested?->get()?->isActive()) {
            throw new SqliteTransactionError('The nested transaction is still active');
        }
    }
}
