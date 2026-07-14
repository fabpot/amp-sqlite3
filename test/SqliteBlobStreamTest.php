<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Test;

use Amp\ByteStream\ClosedException;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\ByteStream\buffer;

final class SqliteBlobStreamTest extends TestCase
{
    /** @var \Fabpot\Amp\Sqlite\SqliteConnection */
    private $connection;

    protected function setUp(): void
    {
        $this->connection = (new SqliteConnector())->connect(new SqliteConfig(':memory:'));
        $this->connection->query('CREATE TABLE files (contents BLOB)');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testReadsBlobIncrementally(): void
    {
        $bytes = \str_repeat('a', 9_000);
        $this->connection->execute('INSERT INTO files VALUES (?)', [new \Fabpot\Amp\Sqlite\SqliteBlob($bytes)]);

        $blob = $this->connection->openBlob('files', 'contents', 1);

        self::assertSame(9_000, $blob->getLength());
        self::assertSame(0, $blob->getPosition());
        self::assertSame(8_192, \strlen($blob->read()));
        self::assertSame(8_192, $blob->getPosition());
        self::assertSame(808, \strlen($blob->read()));
        self::assertNull($blob->read());
        self::assertTrue($blob->isClosed());
    }

    public function testWritesIntoPreallocatedBlob(): void
    {
        $this->connection->query('INSERT INTO files VALUES (zeroblob(6))');
        $blob = $this->connection->openBlob('files', 'contents', 1, mode: SqliteBlobMode::ReadWrite);

        $blob->write('abc');
        $blob->write('def');
        $blob->end();

        self::assertSame(['contents' => '616263646566'], $this->connection->query('SELECT hex(contents) AS contents FROM files')->fetchRow());
    }

    public function testBlobOwnsConnectionUntilClosed(): void
    {
        $this->connection->query('INSERT INTO files VALUES (zeroblob(1))');
        $blob = $this->connection->openBlob('files', 'contents', 1);
        $future = async(fn () => $this->connection->query('SELECT 42 AS answer')->fetchRow());

        self::assertFalse($future->isComplete());
        $blob->close();

        self::assertSame(['answer' => 42], $future->await());
    }

    public function testTransactionCanRollBackBlobWrite(): void
    {
        $this->connection->query('INSERT INTO files VALUES (zeroblob(3))');
        $transaction = $this->connection->beginTransaction();
        $blob = $transaction->openBlob('files', 'contents', 1, mode: SqliteBlobMode::ReadWrite);
        $blob->write('abc');
        $blob->close();
        $transaction->rollback();

        self::assertSame(['contents' => '000000'], $this->connection->query('SELECT hex(contents) AS contents FROM files')->fetchRow());
    }

    public function testRejectsWritingPastBlobLength(): void
    {
        $this->connection->query('INSERT INTO files VALUES (zeroblob(2))');
        $blob = $this->connection->openBlob('files', 'contents', 1, mode: SqliteBlobMode::ReadWrite);

        $this->expectException(\ValueError::class);

        $blob->write('abc');
    }

    public function testReadOnlyBlobRejectsWrites(): void
    {
        $this->connection->query('INSERT INTO files VALUES (zeroblob(1))');
        $blob = $this->connection->openBlob('files', 'contents', 1);

        $this->expectException(ClosedException::class);

        $blob->write('a');
    }

    public function testEmptyBlobReadsAsEmptyStream(): void
    {
        $this->connection->query('INSERT INTO files VALUES (zeroblob(0))');

        self::assertSame('', buffer($this->connection->openBlob('files', 'contents', 1)));
    }

    public function testCloseIsIdempotent(): void
    {
        $this->connection->query('INSERT INTO files VALUES (zeroblob(1))');
        $blob = $this->connection->openBlob('files', 'contents', 1);

        $blob->close();
        $blob->close();

        self::assertTrue($blob->isClosed());
    }

    public function testWorksWithByteStreamBuffer(): void
    {
        $this->connection->execute('INSERT INTO files VALUES (?)', [new \Fabpot\Amp\Sqlite\SqliteBlob('contents')]);

        self::assertSame('contents', buffer($this->connection->openBlob('files', 'contents', 1)));
    }
}
