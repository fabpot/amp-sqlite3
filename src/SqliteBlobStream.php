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

interface SqliteBlobStream extends ReadableStream, WritableStream
{
    public function getLength(): int;

    public function getPosition(): int;
}
