<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Sync\Lock;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteResult;

/** @implements \IteratorAggregate<int, array<string, null|bool|int|float|string|SqliteBlob>> */
final class Result implements SqliteResult, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $onClose;
    private bool $closed = false;
    private bool $explicitlyClosed = false;
    private bool $exhausted;

    /**
     * @param list<array<string, null|bool|int|float|string|SqliteBlob>> $rows
     * @param list<string>|null $columnNames
     * @param null|\Closure(int):array{rows: list<array<string, null|bool|int|float|string|SqliteBlob>>, exhausted: bool} $fetch
     * @param null|\Closure(int):void $close
     * @param null|\Closure():void $onRelease
     */
    public function __construct(
        private array $rows,
        private readonly ?int $rowCount,
        private readonly ?int $columnCount,
        private readonly ?array $columnNames,
        private readonly int $lastInsertId,
        private readonly ?int $resultId,
        bool $exhausted,
        private readonly ?\Closure $fetch,
        private readonly ?\Closure $close,
        private readonly ?Lock $lock,
        private readonly ?\Closure $onRelease,
    ) {
        $this->onClose = new DeferredFuture();
        $this->exhausted = $exhausted;

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
        if ($this->closed) {
            if (!$this->explicitlyClosed && $this->exhausted) {
                return null;
            }

            throw new \Error('The SQLite result is closed');
        }

        if ($this->rows === []) {
            $this->fetchNextBatch();
        }

        $row = \array_shift($this->rows);
        if ($row === null) {
            $this->finish();

            return null;
        }

        if ($this->rows === [] && $this->exhausted) {
            $this->finish();
        }

        return $row;
    }

    public function getIterator(): \Traversable
    {
        while (!$this->closed && ($row = $this->fetchRow()) !== null) {
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

    public function getColumnNames(): ?array
    {
        return $this->columnNames;
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

        $this->explicitlyClosed = true;

        try {
            if ($this->resultId !== null && $this->close !== null) {
                ($this->close)($this->resultId);
            }
        } finally {
            $this->finish();
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
        $this->lock?->release();
        if ($this->onRelease !== null) {
            ($this->onRelease)();
        }
        $this->onClose->complete();
    }
}
