<?php

declare(strict_types=1);

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
