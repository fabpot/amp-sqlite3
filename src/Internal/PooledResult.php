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
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteResult;
use Revolt\EventLoop;

/**
 * Releases its pooled resource as soon as the wrapped result is closed, since SQLite never returns a next result.
 *
 * @internal
 *
 * @implements \IteratorAggregate<int, array<array-key, null|int|float|string|SqliteBlob>>
 */
final class PooledResult implements SqliteResult, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var null|\Closure():void */
    private ?\Closure $release;

    /**
     * @param \Closure():void $release
     */
    public function __construct(
        private readonly SqliteResult $result,
        \Closure $release,
    ) {
        $this->release = $release;
        $this->releaseIfClosed();
    }

    public function __destruct()
    {
        if ($this->release !== null) {
            EventLoop::queue(self::dispose(...), $this->result, $this->release);
        }
    }

    public function fetchRow(): ?array
    {
        try {
            return $this->result->fetchRow();
        } finally {
            $this->releaseIfClosed();
        }
    }

    public function getIterator(): \Traversable
    {
        try {
            foreach ($this->result as $row) {
                $this->releaseIfClosed();

                yield $row;
            }
        } finally {
            $this->releaseIfClosed();
        }
    }

    public function getNextResult(): ?SqliteResult
    {
        return null;
    }

    public function getRowCount(): ?int
    {
        return $this->result->getRowCount();
    }

    public function getColumnCount(): ?int
    {
        return $this->result->getColumnCount();
    }

    public function getColumnNames(): ?array
    {
        return $this->result->getColumnNames();
    }

    public function getLastInsertId(): ?int
    {
        return $this->result->getLastInsertId();
    }

    public function close(): void
    {
        $release = $this->release;
        $this->release = null;
        self::dispose($this->result, $release);
    }

    public function isClosed(): bool
    {
        return $this->result->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->result->onClose($onClose);
    }

    /**
     * @param null|\Closure():void $release
     */
    private static function dispose(SqliteResult $result, ?\Closure $release): void
    {
        try {
            $result->close();
        } finally {
            if ($release !== null) {
                $release();
            }
        }
    }

    private function releaseIfClosed(): void
    {
        if ($this->result->isClosed()) {
            $this->release();
        }
    }

    private function release(): void
    {
        $release = $this->release;
        $this->release = null;
        if ($release !== null) {
            $release();
        }
    }
}
