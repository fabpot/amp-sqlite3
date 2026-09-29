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

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteBlobStream;
use Fabpot\Amp\Sqlite\SqliteConnectionException;
use Revolt\EventLoop;

/**
 * @internal
 *
 * @implements \IteratorAggregate<int, string>
 */
final class BlobStream implements SqliteBlobStream, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;
    use ReadableStreamIteratorAggregate;

    public const DEFAULT_CHUNK_SIZE = 8192;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $onClose;
    private bool $closed = false;
    private bool $connectionClosed = false;
    private bool $readPending = false;
    private int $position = 0;

    /**
     * @param \Closure(int):string $read
     * @param \Closure(string):void $write
     * @param \Closure():void $close
     */
    public function __construct(
        private readonly int $length,
        private readonly SqliteBlobMode $mode,
        private readonly \Closure $read,
        private readonly \Closure $write,
        private readonly \Closure $close,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        $this->onClose = new DeferredFuture();
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        EventLoop::queue(self::dispose(...), $this->close, $this->onClose);
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->readPending) {
            throw new PendingReadError();
        }

        if ($this->closed) {
            if ($this->connectionClosed) {
                throw new SqliteConnectionException('The SQLite connection is closed');
            }

            return null;
        }

        $cancellation?->throwIfRequested();

        if ($this->position === $this->length) {
            $this->close();

            return null;
        }

        $this->readPending = true;
        try {
            $bytes = ($this->read)(\min($this->chunkSize, $this->length - $this->position));

            try {
                $cancellation?->throwIfRequested();
            } catch (CancelledException $exception) {
                try {
                    $this->close();
                } catch (\Throwable) {
                }

                throw $exception;
            }

            if ($bytes === '') {
                $this->close();

                return null;
            }

            $this->position += \strlen($bytes);

            return $bytes;
        } finally {
            $this->readPending = false;
        }
    }

    public function write(string $bytes): void
    {
        if ($this->closed || $this->mode !== SqliteBlobMode::ReadWrite) {
            throw new ClosedException('The SQLite BLOB stream is not writable');
        }

        if (\strlen($bytes) > $this->length - $this->position) {
            throw new \InvalidArgumentException('Writing these bytes would exceed the SQLite BLOB length');
        }

        ($this->write)($bytes);
        $this->position += \strlen($bytes);
    }

    public function end(): void
    {
        if ($this->closed || $this->mode !== SqliteBlobMode::ReadWrite) {
            throw new ClosedException('The SQLite BLOB stream is not writable');
        }

        $this->close();
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isReadable(): bool
    {
        return !$this->closed && $this->position < $this->length;
    }

    public function isWritable(): bool
    {
        return !$this->closed && $this->mode === SqliteBlobMode::ReadWrite && $this->position < $this->length;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        self::dispose($this->close, $this->onClose);
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

    /**
     * @param \Closure():void $close
     * @param DeferredFuture<null> $onClose
     */
    private static function dispose(\Closure $close, DeferredFuture $onClose): void
    {
        try {
            $close();
        } finally {
            $onClose->complete();
        }
    }
}
