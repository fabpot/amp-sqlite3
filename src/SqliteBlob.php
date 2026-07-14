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
