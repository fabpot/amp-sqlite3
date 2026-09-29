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

use Amp\Closable;
use Amp\TimeoutCancellation;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnection;
use Fabpot\Amp\Sqlite\SqliteConnectionPool;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

/**
 * Destructors may run while PHP shuts down, when suspending into the event loop corrupts the engine state.
 */
final class SqliteDestructorTest extends TestCase
{
    private string $path;
    private SqliteConnection $connection;
    private SqliteConnectionPool $pool;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/amp-sqlite-destructor-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $config = (new SqliteConfig($this->path))->withBatchSize(1);
        $this->connection = (new SqliteConnector())->connect($config);
        $this->connection->query('CREATE TABLE entries (value)');
        $this->connection->query("INSERT INTO entries VALUES ('a'), ('b'), (zeroblob(4))");
        $this->pool = new SqliteConnectionPool($config, maxConnections: 2);
    }

    protected function tearDown(): void
    {
        $this->pool->close();
        $this->connection->close();
        @\unlink($this->path);
        @\unlink($this->path . '-shm');
        @\unlink($this->path . '-wal');
    }

    /**
     * @param \Closure(SqliteConnection, SqliteConnectionPool):array{object, mixed} $create
     */
    #[DataProvider('provideDroppedObjects')]
    public function testDroppingAnObjectDoesNotRunTheEventLoop(\Closure $create): void
    {
        [$object, $keep] = $create($this->connection, $this->pool);
        $ranEventLoop = false;
        EventLoop::queue(static function () use (&$ranEventLoop): void {
            $ranEventLoop = true;
        });

        unset($object);

        self::assertFalse($ranEventLoop);
        unset($keep);
        $query = async(fn () => [
            $this->connection->query("SELECT COUNT(*) AS count FROM entries WHERE value = 'dropped'")->fetchRow(),
            $this->pool->query('SELECT 42 AS answer')->fetchRow(),
        ]);
        self::assertSame([['count' => 0], ['answer' => 42]], $query->await(new TimeoutCancellation(5)));
    }

    public static function provideDroppedObjects(): iterable
    {
        yield 'connection' => [static fn (SqliteConnection $connection): array => [
            (new SqliteConnector())->connect(new SqliteConfig(':memory:')),
            null,
        ]];
        yield 'unread result' => [static fn (SqliteConnection $connection): array => [
            $connection->query('SELECT value FROM entries'),
            null,
        ]];
        yield 'statement' => [static fn (SqliteConnection $connection): array => [
            $connection->prepare('SELECT value FROM entries'),
            null,
        ]];
        yield 'BLOB stream' => [static fn (SqliteConnection $connection): array => [
            $connection->openBlob('entries', 'value', 3),
            null,
        ]];
        yield 'transaction' => [static function (SqliteConnection $connection): array {
            $transaction = $connection->beginTransaction();
            $transaction->execute("INSERT INTO entries VALUES ('dropped')");

            return [$transaction, null];
        }];
        yield 'nested transaction' => [static function (SqliteConnection $connection): array {
            $transaction = $connection->beginTransaction();
            $nested = $transaction->beginTransaction();
            $nested->execute("INSERT INTO entries VALUES ('dropped')");

            return [$nested, $transaction];
        }];
        yield 'transaction with an unread result' => [static function (SqliteConnection $connection): array {
            $transaction = $connection->beginTransaction();
            $transaction->execute("INSERT INTO entries VALUES ('dropped')");

            return [[$transaction, $transaction->query('SELECT value FROM entries')], null];
        }];
        yield 'pooled unread result' => [static fn (SqliteConnection $connection, SqliteConnectionPool $pool): array => [
            $pool->query('SELECT value FROM entries'),
            null,
        ]];
        yield 'pooled statement' => [static function (SqliteConnection $connection, SqliteConnectionPool $pool): array {
            $statement = $pool->prepare('SELECT value FROM entries');
            $statement->execute()->close();

            return [$statement, null];
        }];
        yield 'pooled transaction' => [static function (SqliteConnection $connection, SqliteConnectionPool $pool): array {
            $transaction = $pool->beginTransaction();
            $transaction->execute("INSERT INTO entries VALUES ('dropped')");

            return [$transaction, null];
        }];
    }

    public function testDroppedConnectionStopsItsChildProcess(): void
    {
        $factory = new RecordingProcessContextFactory();
        (new SqliteConnector($factory))->connect(new SqliteConfig(':memory:'))->query('SELECT 1');

        for ($attempt = 0; $attempt < 500 && !$factory->context->isClosed(); ++$attempt) {
            delay(0.01);
        }

        self::assertTrue($factory->context->isClosed());
    }

    public function testParentWaitsForTheRollbackOfADroppedNestedTransaction(): void
    {
        $transaction = $this->connection->beginTransaction();
        (static function () use ($transaction): void {
            $nested = $transaction->beginTransaction();
            $nested->execute("INSERT INTO entries VALUES ('dropped')");
        })();

        $transaction->commit();

        self::assertSame(['count' => 0], $this->connection->query("SELECT COUNT(*) AS count FROM entries WHERE value = 'dropped'")->fetchRow());
    }

    public function testFailingToRollBackADroppedTransactionClosesTheConnection(): void
    {
        $transaction = $this->connection->beginTransaction();
        (static function () use ($transaction): void {
            $nested = $transaction->beginTransaction();
            $nested->query('RELEASE SAVEPOINT ' . $nested->getSavepointIdentifier());
        })();

        try {
            $transaction->commit();
            self::fail('Expected the commit to fail after the connection was closed');
        } catch (SqliteTransactionError $error) {
            self::assertSame('The transaction has been committed or rolled back', $error->getMessage());
        }

        self::assertTrue($this->connection->isClosed());
    }

    public function testNewNestedTransactionWaitsForTheRollbackOfADroppedOne(): void
    {
        $transaction = $this->connection->beginTransaction();
        (static function () use ($transaction): void {
            $nested = $transaction->beginTransaction();
            $nested->execute("INSERT INTO entries VALUES ('dropped')");
        })();

        $nested = $transaction->beginTransaction();
        $nested->execute("INSERT INTO entries VALUES ('kept')");
        $nested->commit();
        $transaction->commit();

        self::assertSame(
            [['value' => 'kept']],
            \iterator_to_array($this->connection->query("SELECT value FROM entries WHERE value IN ('dropped', 'kept')")),
        );
    }

    /**
     * @param \Closure(SqliteTransaction):object $open
     */
    #[DataProvider('provideTransactionResources')]
    public function testTransactionCanFinishRightAfterDroppingAnUnreadResource(\Closure $open): void
    {
        $transaction = $this->connection->beginTransaction();
        $transaction->execute("INSERT INTO entries VALUES ('kept')");
        $open($transaction);

        $transaction->commit();

        self::assertSame(['count' => 1], $this->connection->query("SELECT COUNT(*) AS count FROM entries WHERE value = 'kept'")->fetchRow());
    }

    public static function provideTransactionResources(): iterable
    {
        yield 'result' => [static fn (SqliteTransaction $transaction): object => $transaction->query('SELECT value FROM entries')];
        yield 'BLOB stream' => [static fn (SqliteTransaction $transaction): object => $transaction->openBlob('entries', 'value', 3)];
    }

    public function testPooledTransactionCanFinishRightAfterDroppingAnUnreadResult(): void
    {
        $transaction = $this->pool->beginTransaction();
        $transaction->query('SELECT value FROM entries');

        $transaction->commit();

        self::assertSame(['answer' => 42], $this->pool->query('SELECT 42 AS answer')->fetchRow());
    }

    public function testCollectingATransactionTogetherWithItsNestedTransaction(): void
    {
        $cycle = new \stdClass();
        $cycle->cycle = $cycle;
        $cycle->transaction = $this->connection->beginTransaction();
        $cycle->transaction->execute("INSERT INTO entries VALUES ('dropped')");
        $cycle->nested = $cycle->transaction->beginTransaction();
        $cycle->nested->execute("INSERT INTO entries VALUES ('dropped')");
        unset($cycle);

        \gc_collect_cycles();

        $count = async(fn () => $this->connection->query("SELECT COUNT(*) AS count FROM entries WHERE value = 'dropped'")->fetchRow());
        self::assertSame(['count' => 0], $count->await(new TimeoutCancellation(5)));
        self::assertFalse($this->connection->isClosed());
    }

    /**
     * @param \Closure(SqliteConnection):Closable $open
     */
    #[DataProvider('provideClosables')]
    public function testResourceKeptAliveAfterItsDestructorRanIsClosed(\Closure $open): void
    {
        $kept = new \ArrayObject();
        $cycle = new class($kept) {
            public ?object $cycle = null;
            public ?Closable $resource = null;

            /**
             * @param \ArrayObject<int, Closable|null> $kept
             */
            public function __construct(private readonly \ArrayObject $kept)
            {
            }

            public function __destruct()
            {
                $this->kept[] = $this->resource;
            }
        };
        $cycle->cycle = $cycle;
        $cycle->resource = $open($this->connection);
        unset($cycle);
        \gc_collect_cycles();

        $resource = $kept[0];
        self::assertInstanceOf(Closable::class, $resource);
        self::assertTrue($resource->isClosed());
        $resource->close();
        self::assertSame(
            ['answer' => 42],
            async(fn () => $this->connection->query('SELECT 42 AS answer')->fetchRow())->await(new TimeoutCancellation(5)),
        );
    }

    public static function provideClosables(): iterable
    {
        yield 'unread result' => [static fn (SqliteConnection $connection): Closable => $connection->query('SELECT value FROM entries')];
        yield 'statement' => [static fn (SqliteConnection $connection): Closable => $connection->prepare('SELECT value FROM entries')];
        yield 'BLOB stream' => [static fn (SqliteConnection $connection): Closable => $connection->openBlob('entries', 'value', 3)];
        yield 'transaction' => [static fn (SqliteConnection $connection): Closable => $connection->beginTransaction()];
    }
}
