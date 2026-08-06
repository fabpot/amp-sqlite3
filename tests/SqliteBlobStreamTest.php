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

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\PendingReadError;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Fabpot\Amp\Sqlite\Internal\BlobStream;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnectionException;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteException;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

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

    public function testRejectsConcurrentReads(): void
    {
        $stream = new BlobStream(
            2,
            SqliteBlobMode::ReadOnly,
            static function (): string {
                delay(0.05);

                return 'a';
            },
            static function (string $bytes): void {
            },
            static function (): void {
            },
        );
        $first = async(fn () => $stream->read());
        delay(0);

        try {
            $stream->read();
            self::fail('Expected the concurrent read to fail');
        } catch (PendingReadError) {
        }

        self::assertSame('a', $first->await());
        $stream->close();
    }

    public function testCancellationDuringReadClosesBlobAfterDrainingResponse(): void
    {
        $closed = 0;
        $stream = new BlobStream(
            2,
            SqliteBlobMode::ReadOnly,
            static function (): string {
                delay(0.05);

                return 'a';
            },
            static function (string $bytes): void {
            },
            static function () use (&$closed): void {
                ++$closed;
            },
        );
        $cancellation = new DeferredCancellation();
        $read = async(fn () => $stream->read($cancellation->getCancellation()));
        delay(0);
        $cancellation->cancel();

        try {
            $read->await();
            self::fail('Expected the pending read to be cancelled');
        } catch (CancelledException) {
        }

        self::assertTrue($stream->isClosed());
        self::assertSame(0, $stream->getPosition());
        self::assertSame(1, $closed);
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

    public function testBlobOperationFailuresUseGeneralSqliteException(): void
    {
        $this->expectException(SqliteException::class);

        $this->connection->openBlob('files', 'contents', 999);
    }

    public function testRejectsWritingPastBlobLength(): void
    {
        $this->connection->query('INSERT INTO files VALUES (zeroblob(2))');
        $blob = $this->connection->openBlob('files', 'contents', 1, mode: SqliteBlobMode::ReadWrite);

        $this->expectException(\InvalidArgumentException::class);

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

    public function testProcessFailureInvalidatesBlobAndReleasesWaitingOperation(): void
    {
        $factory = new RecordingProcessContextFactory();
        $connection = (new SqliteConnector($factory))->connect(new SqliteConfig(':memory:'));
        $connection->query('CREATE TABLE files (contents BLOB)');
        $connection->query("INSERT INTO files VALUES (zeroblob(4))");
        $blob = $connection->openBlob('files', 'contents', 1);
        $closed = 0;
        $blob->onClose(static function () use (&$closed): void {
            ++$closed;
        });
        $future = async(fn () => $connection->query('SELECT 1'));
        delay(0);
        self::assertFalse($future->isComplete());

        $factory->context->close();
        try {
            $blob->read();
            self::fail('Expected the child-process failure to close the BLOB');
        } catch (SqliteConnectionException) {
        }
        delay(0);

        self::assertTrue($future->isComplete());
        self::assertTrue($blob->isClosed());
        self::assertSame(1, $closed);

        $this->expectException(SqliteConnectionException::class);
        $future->await();
    }

    public function testConnectionCloseInvalidatesBlobAndReleasesWaitingOperation(): void
    {
        $this->connection->query("INSERT INTO files VALUES (zeroblob(4))");
        $blob = $this->connection->openBlob('files', 'contents', 1);
        $closed = 0;
        $blob->onClose(static function () use (&$closed): void {
            ++$closed;
        });
        $future = async(fn () => $this->connection->query('SELECT 1'));
        delay(0);

        self::assertFalse($future->isComplete());
        $this->connection->close();
        delay(0);

        self::assertTrue($future->isComplete());
        self::assertTrue($blob->isClosed());
        self::assertSame(1, $closed);

        $this->expectException(SqliteConnectionException::class);
        $future->await();
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
