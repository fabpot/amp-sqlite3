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
use Amp\Sync\Lock;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteConnectionException;
use Fabpot\Amp\Sqlite\SqliteException;
use Fabpot\Amp\Sqlite\SqliteResult;

/**
 * @internal
 *
 * @implements \IteratorAggregate<int, array<array-key, null|int|float|string|SqliteBlob>>
 */
final class Result implements SqliteResult, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $onClose;
    private bool $closed = false;
    private bool $explicitlyClosed = false;
    private bool $connectionClosed = false;
    private bool $exhausted;
    private int $position = 0;
    private ?Transaction $transaction;

    /**
     * @param list<array<array-key, null|int|float|string|SqliteBlob>> $rows
     * @param list<string>|null $columnNames
     * @param null|\Closure(int):array{rows: list<array<array-key, null|int|float|string|SqliteBlob>>, exhausted: bool} $fetch
     * @param null|\Closure(int):void $close
     */
    public function __construct(
        private array $rows,
        private readonly ?int $rowCount,
        private readonly ?int $columnCount,
        private readonly ?array $columnNames,
        private readonly ?int $lastInsertId,
        private readonly ?int $resultId,
        bool $exhausted,
        private readonly ?\Closure $fetch,
        private readonly ?\Closure $close,
        private readonly ?Lock $lease,
        ?Transaction $transaction = null,
    ) {
        $this->onClose = new DeferredFuture();
        $this->exhausted = $exhausted;
        $this->transaction = $transaction;

        if ($exhausted && $rows === []) {
            $this->finish();
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function fetchRow(): ?array
    {
        if ($this->closed || $this->explicitlyClosed) {
            if ($this->connectionClosed) {
                throw new SqliteConnectionException('The SQLite connection is closed');
            }
            if (!$this->explicitlyClosed && $this->exhausted) {
                return null;
            }

            throw new SqliteException('The SQLite result is closed');
        }

        if (!isset($this->rows[$this->position])) {
            $this->fetchNextBatch();
        }

        $row = $this->rows[$this->position++] ?? null;
        if ($row === null) {
            $this->finish();

            return null;
        }

        if (!isset($this->rows[$this->position]) && $this->exhausted) {
            $this->finish();
        }

        return $row;
    }

    public function getIterator(): \Traversable
    {
        // Unlike fetchRow(), iteration ends silently on an explicitly closed result
        while (!$this->closed && !$this->explicitlyClosed && ($row = $this->fetchRow()) !== null) {
            yield $row;
        }

        if ($this->connectionClosed) {
            throw new SqliteConnectionException('The SQLite connection is closed');
        }
    }

    public function getNextResult(): ?SqliteResult
    {
        return null;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function getColumnCount(): ?int
    {
        return $this->columnCount;
    }

    public function getColumnNames(): ?array
    {
        return $this->columnNames;
    }

    public function getLastInsertId(): ?int
    {
        return $this->lastInsertId;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->explicitlyClosed = true;

        try {
            if ($this->resultId !== null && $this->close !== null) {
                ($this->close)($this->resultId);
            }
        } finally {
            $this->finish();
        }
    }

    public function closeOnConnectionClose(): void
    {
        if ($this->closed) {
            return;
        }

        $this->connectionClosed = true;
        $this->close();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    private function fetchNextBatch(): void
    {
        if ($this->resultId === null || $this->fetch === null) {
            return;
        }

        try {
            $batch = ($this->fetch)($this->resultId);
        } catch (\Throwable $exception) {
            try {
                $this->close();
            } catch (\Throwable) {
            }

            throw $exception;
        }

        $this->rows = $batch['rows'];
        $this->position = 0;
        $this->exhausted = $batch['exhausted'];
        if ($this->exhausted && $this->rows === []) {
            $this->finish();
        }
    }

    private function finish(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->rows = [];
        $this->position = 0;
        $this->lease?->release();
        if ($this->transaction !== null) {
            $this->transaction = null;
        }
        $this->onClose->complete();
    }
}
