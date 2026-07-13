<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

final class SqliteBlob
{
    public function __construct(private readonly string $bytes)
    {
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }

    public function getLength(): int
    {
        return \strlen($this->bytes);
    }
}
