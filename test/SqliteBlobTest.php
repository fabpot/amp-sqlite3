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

namespace Fabpot\Amp\Sqlite\Test;

use Fabpot\Amp\Sqlite\SqliteBlob;
use PHPUnit\Framework\TestCase;

final class SqliteBlobTest extends TestCase
{
    public function testPreservesArbitraryBytesWhenSerialized(): void
    {
        $blob = new SqliteBlob("\0\xffcontents");
        $copy = \unserialize(\serialize($blob));

        self::assertInstanceOf(SqliteBlob::class, $copy);
        self::assertSame("\0\xffcontents", $copy->getBytes());
        self::assertSame(10, $copy->getLength());
    }
}
