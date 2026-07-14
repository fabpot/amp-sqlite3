<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteBlobStream;

/** @implements \IteratorAggregate<int, string> */
final class BlobStream implements SqliteBlobStream, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;
    use ReadableStreamIteratorAggregate;

    public const DEFAULT_CHUNK_SIZE = 8192;

    private readonly DeferredFuture $onClose;
    private bool $closed = false;
    private int $position = 0;

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
        $this->close();
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        $cancellation?->throwIfRequested();

        if ($this->closed) {
            return null;
        }

        if ($this->position === $this->length) {
            $this->close();

            return null;
        }

        $bytes = ($this->read)(\min($this->chunkSize, $this->length - $this->position));
        if ($bytes === '') {
            $this->close();

            return null;
        }

        $this->position += \strlen($bytes);

        return $bytes;
    }

    public function write(string $bytes): void
    {
        if (!$this->isWritable()) {
            throw new ClosedException('The SQLite BLOB stream is not writable');
        }

        if (\strlen($bytes) > $this->length - $this->position) {
            throw new \ValueError('Writing these bytes would exceed the SQLite BLOB length');
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
        try {
            ($this->close)();
        } finally {
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
}
