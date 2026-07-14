<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteBlobStream;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;

final class Transaction implements SqliteTransaction
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $onCommit;
    private readonly DeferredFuture $onRollback;
    private readonly DeferredFuture $onClose;
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
        $connection->onClose(function (): void {
            if ($this->active) {
                $this->active = false;
                $this->onRollback->complete();
                $this->onClose->complete();
            }
        });
    }

    public function __destruct()
    {
        $this->close();
    }

    public function query(string $sql): SqliteResult
    {
        $this->assertActive();

        return $this->connection->queryInTransaction($sql);
    }

    public function openBlob(
        string $table,
        string $column,
        int $rowId,
        string $database = 'main',
        SqliteBlobMode $mode = SqliteBlobMode::ReadOnly,
    ): SqliteBlobStream {
        $this->assertActive();

        return $this->connection->openBlobInTransaction($table, $column, $rowId, $database, $mode);
    }

    public function prepare(string $sql): SqliteStatement
    {
        $this->assertActive();

        return $this->connection->prepareInTransaction($sql);
    }

    public function execute(string $sql, array $params = []): SqliteResult
    {
        $this->assertActive();

        return $this->connection->executeInTransaction($sql, $params);
    }

    public function beginTransaction(): SqliteTransaction
    {
        $this->assertActive();
        if ($this->activeNested !== null && $this->activeNested->isActive()) {
            throw new SqliteTransactionError('The nested transaction is still active');
        }

        $savepoint = 'amp_sqlite_' . $this->nextSavepointId++;
        $this->connection->executeControl("SAVEPOINT {$savepoint}");
        $this->nestedBusy = new DeferredFuture();
        $transaction = new self($this->connection, $this->mode, $this, $savepoint);
        $this->activeNested = $transaction;

        return $transaction;
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
        $this->assertActive();
        $this->assertNoActiveNestedTransaction();
        $this->active = false;

        try {
            $this->connection->executeControl($this->savepoint === null ? 'COMMIT' : "RELEASE SAVEPOINT {$this->savepoint}");
        } finally {
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
        }
    }

    public function rollback(): void
    {
        $this->assertActive();
        $this->assertNoActiveNestedTransaction();
        $this->active = false;

        try {
            if ($this->savepoint === null) {
                $this->connection->executeControl('ROLLBACK');
            } else {
                $this->connection->executeControl("ROLLBACK TO SAVEPOINT {$this->savepoint}");
                $this->connection->executeControl("RELEASE SAVEPOINT {$this->savepoint}");
            }
        } finally {
            $this->parent?->releaseNested($this);
            $this->onRollback->complete();
            $this->onClose->complete();
            if ($this->savepoint === null) {
                $this->connection->releaseTransaction($this);
            }
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
        if (!$this->active) {
            return;
        }

        if ($this->connection->isClosed()) {
            $this->active = false;
            $this->onRollback->complete();
            $this->onClose->complete();

            return;
        }

        $this->rollback();
    }

    public function isClosed(): bool
    {
        return !$this->isActive();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    private function releaseNested(self $transaction): void
    {
        if ($this->activeNested === $transaction) {
            $this->activeNested = null;
            $this->nestedBusy?->complete();
            $this->nestedBusy = null;
        }
    }

    private function assertActive(): void
    {
        while ($this->nestedBusy !== null) {
            $this->nestedBusy->getFuture()->await();
        }

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
