<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Test;

use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteQueryError;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class SqliteStatementTest extends TestCase
{
    /** @var \Fabpot\Amp\Sqlite\SqliteConnection */
    private $connection;

    protected function setUp(): void
    {
        $this->connection = (new SqliteConnector())->connect((new SqliteConfig(':memory:'))->withBatchSize(1));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testReusesNativeStatement(): void
    {
        $this->connection->query('CREATE TABLE files (name TEXT, contents BLOB)');
        $statement = $this->connection->prepare('INSERT INTO files VALUES (:name, :contents)');

        $statement->execute([':name' => 'first', ':contents' => new SqliteBlob('one')]);
        $statement->execute([':name' => 'second', ':contents' => new SqliteBlob('two')]);

        $rows = \iterator_to_array($this->connection->query('SELECT * FROM files ORDER BY name'));
        self::assertSame('first', $rows[0]['name']);
        self::assertSame('one', $rows[0]['contents']->getBytes());
        self::assertSame('second', $rows[1]['name']);
        self::assertSame('two', $rows[1]['contents']->getBytes());
        self::assertSame('INSERT INTO files VALUES (:name, :contents)', $statement->getQuery());
    }

    public function testResetsBindingsBetweenExecutions(): void
    {
        $statement = $this->connection->prepare('SELECT :value AS value');

        self::assertSame(['value' => 'first'], $statement->execute([':value' => 'first'])->fetchRow());
        self::assertSame(['value' => 'second'], $statement->execute([':value' => 'second'])->fetchRow());
    }

    public function testCanReuseStatementAfterExecutionError(): void
    {
        $this->connection->query('CREATE TABLE unique_values (value INTEGER UNIQUE)');
        $statement = $this->connection->prepare('INSERT INTO unique_values VALUES (?)');
        $statement->execute([1]);

        try {
            $statement->execute([1]);
            self::fail('Expected the duplicate value to fail');
        } catch (SqliteQueryError) {
        }

        $statement->execute([2]);

        self::assertSame(
            [['value' => 1], ['value' => 2]],
            \iterator_to_array($this->connection->query('SELECT value FROM unique_values ORDER BY value')),
        );
    }

    public function testCloseIsIdempotentAndPreventsExecution(): void
    {
        $statement = $this->connection->prepare('SELECT 1');
        $closed = 0;
        $statement->onClose(static function () use (&$closed): void {
            ++$closed;
        });

        $statement->close();
        $statement->close();
        EventLoop::run();

        self::assertSame(1, $closed);
        self::assertTrue($statement->isClosed());

        $this->expectException(\Error::class);
        $statement->execute();
    }

    public function testClosingStatementClosesActiveResult(): void
    {
        $statement = $this->connection->prepare('SELECT 1 AS value UNION ALL SELECT 2');
        $result = $statement->execute();

        self::assertSame(['value' => 1], $result->fetchRow());
        $statement->close();

        self::assertTrue($result->isClosed());
        self::assertSame(['value' => 3], $this->connection->query('SELECT 3 AS value')->fetchRow());
    }
}
