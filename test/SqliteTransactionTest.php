<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Test;

use Amp\Sql\SqlTransactionIsolationLevel;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use function Amp\async;

final class SqliteTransactionTest extends TestCase
{
    /** @var \Fabpot\Amp\Sqlite\SqliteConnection */
    private $connection;

    protected function setUp(): void
    {
        $this->connection = (new SqliteConnector())->connect((new SqliteConfig(':memory:'))->withBatchSize(1));
        $this->connection->query('CREATE TABLE entries (value TEXT)');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testCommitsAndRollsBack(): void
    {
        $transaction = $this->connection->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['committed']);
        $transaction->commit();

        $transaction = $this->connection->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['rolled back']);
        $transaction->rollback();

        self::assertSame([['value' => 'committed']], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testUsesConfiguredTransactionMode(): void
    {
        $this->connection->setTransactionIsolation(SqliteTransactionMode::Immediate);
        $transaction = $this->connection->beginTransaction();

        self::assertSame(SqliteTransactionMode::Immediate, $transaction->getIsolation());

        $transaction->rollback();
    }

    public function testRejectsGenericIsolationLevel(): void
    {
        $this->expectException(\TypeError::class);

        $this->connection->setTransactionIsolation(SqlTransactionIsolationLevel::Serializable);
    }

    public function testNestedCommitAndRollbackUseSavepoints(): void
    {
        $transaction = $this->connection->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['outer']);

        $committed = $transaction->beginTransaction();
        $committed->execute('INSERT INTO entries VALUES (?)', ['nested commit']);
        $committed->commit();

        $rolledBack = $transaction->beginTransaction();
        $rolledBack->execute('INSERT INTO entries VALUES (?)', ['nested rollback']);
        $rolledBack->rollback();

        $transaction->commit();

        self::assertSame(
            [['value' => 'outer'], ['value' => 'nested commit']],
            \iterator_to_array($this->connection->query('SELECT value FROM entries')),
        );
    }

    public function testParentWaitsForNestedTransaction(): void
    {
        $transaction = $this->connection->beginTransaction();
        $nested = $transaction->beginTransaction();
        $future = async(fn () => $transaction->execute('INSERT INTO entries VALUES (?)', ['after nested']));

        self::assertFalse($future->isComplete());
        $nested->commit();
        $future->await();
        $transaction->commit();

        self::assertSame([['value' => 'after nested']], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testTransactionStatementWaitsForNestedTransaction(): void
    {
        $transaction = $this->connection->beginTransaction();
        $statement = $transaction->prepare('INSERT INTO entries VALUES (?)');
        $nested = $transaction->beginTransaction();
        $future = async(fn () => $statement->execute(['after nested']));

        self::assertFalse($future->isComplete());
        $nested->commit();
        $future->await();
        $transaction->commit();

        try {
            $statement->execute(['after commit']);
            self::fail('Expected the statement execution to fail');
        } catch (\Fabpot\Amp\Sqlite\SqliteTransactionError $error) {
            self::assertSame('The transaction has been committed or rolled back', $error->getMessage());
        }

        $statement->close();
        self::assertSame([['value' => 'after nested']], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testTransactionWaitsForActiveResult(): void
    {
        $transaction = $this->connection->beginTransaction();
        $result = $transaction->query("SELECT 'first' AS value UNION ALL SELECT 'second'");
        $future = async(fn () => $transaction->execute('INSERT INTO entries VALUES (?)', ['after result']));

        self::assertFalse($future->isComplete());
        $result->close();
        $future->await();
        $transaction->commit();

        self::assertSame([['value' => 'after result']], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testNestedCommitCallbackWaitsForTopLevelCommit(): void
    {
        $transaction = $this->connection->beginTransaction();
        $nested = $transaction->beginTransaction();
        $commits = 0;
        $nested->onCommit(static function () use (&$commits): void {
            ++$commits;
        });

        $nested->commit();
        EventLoop::run();
        self::assertSame(0, $commits);

        $transaction->commit();
        EventLoop::run();
        self::assertSame(1, $commits);
    }

    public function testFailedCommitKeepsTransactionActive(): void
    {
        $this->connection->query('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $this->connection->query('CREATE TABLE children (parent_id INTEGER REFERENCES parents(id) DEFERRABLE INITIALLY DEFERRED)');
        $transaction = $this->connection->beginTransaction();
        $commits = 0;
        $rollbacks = 0;
        $transaction->onCommit(static function () use (&$commits): void {
            ++$commits;
        });
        $transaction->onRollback(static function () use (&$rollbacks): void {
            ++$rollbacks;
        });
        $transaction->execute('INSERT INTO children VALUES (1)');

        try {
            $transaction->commit();
            self::fail('Expected the deferred foreign key check to fail');
        } catch (\Fabpot\Amp\Sqlite\SqliteQueryError) {
        }

        self::assertTrue($transaction->isActive());
        $transaction->rollback();
        EventLoop::run();
        self::assertSame(0, $commits);
        self::assertSame(1, $rollbacks);

        $transaction = $this->connection->beginTransaction();
        $transaction->rollback();
    }

    public function testCallbacksRunOnce(): void
    {
        $transaction = $this->connection->beginTransaction();
        $commits = 0;
        $rollbacks = 0;
        $transaction->onCommit(static function () use (&$commits): void {
            ++$commits;
        });
        $transaction->onRollback(static function () use (&$rollbacks): void {
            ++$rollbacks;
        });

        $transaction->commit();
        EventLoop::run();

        self::assertSame(1, $commits);
        self::assertSame(0, $rollbacks);
    }

    public function testConnectionFailureRunsRollbackCallback(): void
    {
        $factory = new RecordingProcessContextFactory();
        $connection = (new SqliteConnector($factory))->connect(new SqliteConfig(':memory:'));
        $transaction = $connection->beginTransaction();
        $rollbacks = 0;
        $transaction->onRollback(static function () use (&$rollbacks): void {
            ++$rollbacks;
        });

        $factory->context->close();

        try {
            $transaction->query('SELECT 1');
            self::fail('Expected the connection to fail');
        } catch (\Amp\Sql\SqlConnectionException) {
        }
        EventLoop::run();

        self::assertFalse($transaction->isActive());
        self::assertSame(1, $rollbacks);
        $connection->close();
    }

    public function testConnectionCloseReleasesParentWaitingForNestedTransaction(): void
    {
        $transaction = $this->connection->beginTransaction();
        $transaction->beginTransaction();
        $future = async(fn () => $transaction->query('SELECT 1'));

        self::assertFalse($future->isComplete());
        $this->connection->close();

        try {
            $future->await();
            self::fail('Expected the closed transaction to reject the query');
        } catch (\Fabpot\Amp\Sqlite\SqliteTransactionError $error) {
            self::assertSame('The transaction has been committed or rolled back', $error->getMessage());
        }
    }

    public function testCloseRollsBack(): void
    {
        $transaction = $this->connection->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['rolled back']);
        $transaction->close();

        self::assertSame([], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }
}

final class RecordingProcessContextFactory implements \Amp\Parallel\Context\ContextFactory
{
    public \Amp\Parallel\Context\ProcessContext $context;

    public function start(string|array $script, ?\Amp\Cancellation $cancellation = null): \Amp\Parallel\Context\Context
    {
        return $this->context = (new \Amp\Parallel\Context\ProcessContextFactory())->start($script, $cancellation);
    }
}
