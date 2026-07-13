<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\DeferredFuture;
use Fabpot\Amp\Sqlite\SqliteResult;

/** @implements \IteratorAggregate<int, array<string, null|bool|int|float|string|\Fabpot\Amp\Sqlite\SqliteBlob>> */
final class Result implements SqliteResult, \IteratorAggregate
{
    private readonly DeferredFuture $onClose;
    private bool $closed = false;

    /** @param list<array<string, null|bool|int|float|string|\Fabpot\Amp\Sqlite\SqliteBlob>> $rows */
    public function __construct(
        private array $rows,
        private readonly ?int $rowCount,
        private readonly ?int $columnCount,
        private readonly int $lastInsertId,
    ) {
        $this->onClose = new DeferredFuture();
    }

    public function fetchRow(): ?array
    {
        if ($this->closed) {
            return null;
        }

        $row = \array_shift($this->rows);
        if ($row === null) {
            $this->close();
        }

        return $row;
    }

    public function getIterator(): \Traversable
    {
        while (($row = $this->fetchRow()) !== null) {
            yield $row;
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

    public function getLastInsertId(): int
    {
        return $this->lastInsertId;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->rows = [];
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
}
