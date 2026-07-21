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

use Amp\Sql\SqlTransactionIsolationLevel;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnection;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

final class SqliteTransactionTest extends TestCase
{
    private SqliteConnection $connection;

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

    public function testConcurrentBeginTransactionCallsSerialize(): void
    {
        $first = async(fn () => $this->connection->beginTransaction());
        $second = async(fn () => $this->connection->beginTransaction());

        $transaction = $first->await();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['first']);
        $transaction->commit();

        $transaction = $second->await();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['second']);
        $transaction->rollback();

        self::assertSame([['value' => 'first']], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
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

    public function testConcurrentNestedTransactionsCannotCorruptSavepoints(): void
    {
        $transaction = $this->connection->beginTransaction();
        $first = async(fn () => $transaction->beginTransaction());
        $second = async(fn () => $transaction->beginTransaction());

        $nested = $first->await();
        try {
            $second->await();
            self::fail('Expected the concurrent nested transaction to be rejected');
        } catch (\Fabpot\Amp\Sqlite\SqliteTransactionError $error) {
            self::assertSame('The nested transaction is still active', $error->getMessage());
        }

        $nested->rollback();
        $transaction->rollback();
    }

    public function testConcurrentTransactionFinalizationIsSerialized(): void
    {
        $transaction = $this->connection->beginTransaction();
        $commit = async(fn () => $transaction->commit());
        $rollback = async(fn () => $transaction->rollback());

        $commit->await();
        try {
            $rollback->await();
            self::fail('Expected the second finalization to be rejected');
        } catch (\Fabpot\Amp\Sqlite\SqliteTransactionError $error) {
            self::assertSame('The transaction has been committed or rolled back', $error->getMessage());
        }

        self::assertFalse($transaction->isActive());
    }

    public function testCommitCannotRaceWithTransactionExecution(): void
    {
        $transaction = $this->connection->beginTransaction();
        $commit = async(fn () => $transaction->commit());
        $execute = async(fn () => $transaction->execute('INSERT INTO entries VALUES (?)', ['too late']));

        $commit->await();
        try {
            $execute->await();
            self::fail('Expected execution after commit to be rejected');
        } catch (\Fabpot\Amp\Sqlite\SqliteTransactionError $error) {
            self::assertSame('The transaction has been committed or rolled back', $error->getMessage());
        }

        self::assertSame([], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testTransactionStatementCannotRaceWithCommit(): void
    {
        $transaction = $this->connection->beginTransaction();
        $statement = $transaction->prepare('INSERT INTO entries VALUES (?)');
        $commit = async(fn () => $transaction->commit());
        $execute = async(fn () => $statement->execute(['too late']));

        $commit->await();
        try {
            $execute->await();
            self::fail('Expected statement execution after commit to be rejected');
        } catch (\Fabpot\Amp\Sqlite\SqliteTransactionError $error) {
            self::assertSame('The transaction has been committed or rolled back', $error->getMessage());
        }

        $statement->close();
        self::assertSame([], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
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

    public function testActiveResultKeepsTransactionAlive(): void
    {
        $transaction = $this->connection->beginTransaction();
        $result = $transaction->query("SELECT 'first' AS value UNION ALL SELECT 'second'");
        unset($transaction);
        \gc_collect_cycles();

        self::assertSame(['value' => 'first'], $result->fetchRow());
        $result->close();
        unset($result);
        \gc_collect_cycles();

        self::assertSame(['answer' => 42], $this->connection->query('SELECT 42 AS answer')->fetchRow());
    }

    public function testConcurrentTransactionalQueriesSerializeWithoutLosingWakeups(): void
    {
        $this->connection->execute('INSERT INTO entries VALUES (?), (?), (?)', ['a', 'b', 'c']);
        $transaction = $this->connection->beginTransaction();

        $first = async(fn () => \iterator_to_array($transaction->query('SELECT value FROM entries ORDER BY value')));
        $second = async(fn () => \iterator_to_array($transaction->query('SELECT value FROM entries ORDER BY value DESC')));
        $third = async(fn () => $transaction->execute('INSERT INTO entries VALUES (?)', ['late']));

        self::assertCount(3, $first->await());
        self::assertCount(3, $second->await());
        $third->await();
        $transaction->commit();

        self::assertSame(['c' => 4], $this->connection->query('SELECT COUNT(*) AS c FROM entries')->fetchRow());
    }

    public function testCommitWaitsForResultOpenedConcurrentlyWithAnotherResult(): void
    {
        $this->connection->execute('INSERT INTO entries VALUES (?), (?)', ['a', 'b']);
        $transaction = $this->connection->beginTransaction();

        $first = async(fn () => $transaction->query('SELECT value FROM entries ORDER BY value'));
        $second = async(fn () => $transaction->query('SELECT value FROM entries ORDER BY value DESC'));

        $firstResult = $first->await();
        $firstResult->close();
        $secondResult = $second->await();

        $commit = async(fn () => $transaction->commit());
        delay(0.05);
        self::assertFalse($commit->isComplete());

        $secondResult->close();
        $commit->await();

        self::assertTrue($transaction->isClosed());
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
        delay(0);
        self::assertSame(0, $commits);

        $transaction->commit();
        delay(0);
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
        delay(0);
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
        delay(0);

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
        delay(0);

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

    public function testAbandonedTransactionRollsBackAndReleasesConnection(): void
    {
        (function (): void {
            $transaction = $this->connection->beginTransaction();
            $transaction->execute('INSERT INTO entries VALUES (?)', ['abandoned']);
        })();

        self::assertSame([], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testFinishedTransactionsAreGarbageCollectable(): void
    {
        $transaction = $this->connection->beginTransaction();
        $reference = \WeakReference::create($transaction);
        $transaction->commit();
        unset($transaction);
        \gc_collect_cycles();

        self::assertNull($reference->get());
    }

    public function testCloseRollsBack(): void
    {
        $transaction = $this->connection->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['rolled back']);
        $transaction->close();

        self::assertSame([], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testCommitRejectsActiveNestedTransactionWithoutWaiting(): void
    {
        $transaction = $this->connection->beginTransaction();
        $nested = $transaction->beginTransaction();

        try {
            $transaction->commit();
            self::fail('Expected the active nested transaction to be rejected');
        } catch (\Fabpot\Amp\Sqlite\SqliteTransactionError $error) {
            self::assertSame('The nested transaction is still active', $error->getMessage());
        }

        $nested->rollback();
        $transaction->rollback();
    }

    public function testClosingParentRollsBackActiveNestedTransaction(): void
    {
        $transaction = $this->connection->beginTransaction();
        $nested = $transaction->beginTransaction();
        $nested->execute('INSERT INTO entries VALUES (?)', ['nested']);

        $transaction->close();

        self::assertFalse($nested->isActive());
        self::assertFalse($transaction->isActive());
        self::assertSame([], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testActiveBlobKeepsTransactionAlive(): void
    {
        $this->connection->query('CREATE TABLE files (contents BLOB)');
        $this->connection->query('INSERT INTO files VALUES (zeroblob(1))');
        $transaction = $this->connection->beginTransaction();
        $blob = $transaction->openBlob('files', 'contents', 1);
        unset($transaction);
        \gc_collect_cycles();

        self::assertSame("\0", $blob->read());
        $blob->close();
        unset($blob);
        \gc_collect_cycles();

        self::assertSame(['answer' => 42], $this->connection->query('SELECT 42 AS answer')->fetchRow());
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
