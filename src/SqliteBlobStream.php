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

namespace Fabpot\Amp\Sqlite;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;

interface SqliteBlobStream extends ReadableStream, WritableStream
{
    /**
     * @throws \Amp\ByteStream\PendingReadError If another read is already pending.
     * @throws \Amp\ByteStream\StreamException If the stream cannot be read.
     * @throws \Amp\CancelledException If the read is cancelled.
     * @throws SqliteConnectionException If the connection is lost while reading.
     * @throws SqliteException If SQLite cannot read the BLOB.
     */
    public function read(?Cancellation $cancellation = null): ?string;

    public function getLength(): int;

    public function getPosition(): int;
}
