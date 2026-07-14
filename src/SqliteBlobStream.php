<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;

interface SqliteBlobStream extends ReadableStream, WritableStream
{
    public function getLength(): int;

    public function getPosition(): int;
}
