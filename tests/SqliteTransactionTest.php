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
use Amp\TimeoutCancellation;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnection;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteException;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransaction;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testFinishingTransactionClosesPreparedStatements(): void
    {
        $committed = $this->connection->beginTransaction();
        $committedStatement = $committed->prepare('SELECT 1');
        $committedClosed = 0;
        $committedStatement->onClose(static function () use (&$committedClosed): void {
            ++$committedClosed;
        });
        $committed->commit();

        $rolledBack = $this->connection->beginTransaction();
        $rolledBackStatement = $rolledBack->prepare('SELECT 1');
        $rolledBackClosed = 0;
        $rolledBackStatement->onClose(static function () use (&$rolledBackClosed): void {
            ++$rolledBackClosed;
        });
        $rolledBack->rollback();
        delay(0);

        self::assertTrue($committedStatement->isClosed());
        self::assertTrue($rolledBackStatement->isClosed());
        self::assertSame(1, $committedClosed);
        self::assertSame(1, $rolledBackClosed);

        $this->expectException(SqliteException::class);
        $committedStatement->execute();
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
        $this->expectException(\InvalidArgumentException::class);

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

    public function testBeginTransactionWaitsForActiveTransaction(): void
    {
        $first = $this->connection->beginTransaction();
        $second = async(fn () => $this->connection->beginTransaction());
        delay(0.05);

        self::assertFalse($second->isComplete());
        $first->execute('INSERT INTO entries VALUES (?)', ['first']);
        $first->commit();

        $transaction = $second->await();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['second']);
        $transaction->commit();

        self::assertSame([['value' => 'first'], ['value' => 'second']], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
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

    public function testParentFinalizationDoesNotDeadlockBehindOperationWaitingForNestedTransaction(): void
    {
        $transaction = $this->connection->beginTransaction();
        $nested = $transaction->beginTransaction();
        $query = async(fn () => $transaction->query('SELECT 1'));
        delay(0);
        $close = async(fn () => $transaction->close());
        $close->await();

        self::assertTrue($close->isComplete());
        self::assertFalse($transaction->isActive());

        try {
            $query->await();
            self::fail('Expected the waiting query to be rejected');
        } catch (\Fabpot\Amp\Sqlite\SqliteTransactionError $error) {
            self::assertSame('The transaction has been committed or rolled back', $error->getMessage());
        }
        self::assertFalse($nested->isActive());
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
        } catch (SqliteException|SqliteTransactionError $error) {
            self::assertContains($error->getMessage(), [
                'The SQLite statement is closed',
                'The transaction has been committed or rolled back',
            ]);
        }

        self::assertTrue($statement->isClosed());
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
        } catch (SqliteException $error) {
            self::assertSame('The SQLite statement is closed', $error->getMessage());
        }

        self::assertTrue($statement->isClosed());
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

    /**
     * @param \Closure(SqliteTransaction):mixed $finish
     */
    #[DataProvider('provideTransactionControlOperations')]
    public function testRejectsTransactionControlWhileCurrentTaskHoldsUnreadResult(\Closure $finish): void
    {
        $transaction = $this->connection->beginTransaction();
        $result = $transaction->query("SELECT 'first' AS value UNION ALL SELECT 'second'");

        try {
            $finish($transaction);
            self::fail('Expected the unread result to be rejected');
        } catch (SqliteTransactionError $error) {
            self::assertSame('Close the unread results and BLOB streams of the transaction first', $error->getMessage());
        }

        $result->close();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['committed']);
        $transaction->commit();

        self::assertSame([['value' => 'committed']], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public static function provideTransactionControlOperations(): iterable
    {
        yield 'commit' => [static fn (SqliteTransaction $transaction) => $transaction->commit()];
        yield 'rollback' => [static fn (SqliteTransaction $transaction) => $transaction->rollback()];
        yield 'nested transaction' => [static fn (SqliteTransaction $transaction) => $transaction->beginTransaction()];
    }

    public function testRejectsCommitWhileCurrentTaskHoldsOpenBlob(): void
    {
        $this->connection->query('CREATE TABLE files (contents BLOB)');
        $this->connection->query('INSERT INTO files VALUES (zeroblob(1))');
        $transaction = $this->connection->beginTransaction();
        $blob = $transaction->openBlob('files', 'contents', 1);

        try {
            $transaction->commit();
            self::fail('Expected the open BLOB stream to be rejected');
        } catch (SqliteTransactionError $error) {
            self::assertSame('Close the unread results and BLOB streams of the transaction first', $error->getMessage());
        }

        $blob->close();
        $transaction->commit();

        self::assertFalse($transaction->isActive());
    }

    public function testCommitQueuedByTaskHoldingResultWaitsForResult(): void
    {
        $transaction = $this->connection->beginTransaction();
        $commit = null;
        $result = async(function () use ($transaction, &$commit) {
            $result = $transaction->query("SELECT 'first' AS value UNION ALL SELECT 'second'");
            $commit = async(fn () => $transaction->commit());

            return $result;
        })->await();
        delay(0.05);

        self::assertFalse($commit->isComplete());
        $result->close();
        $commit->await();

        self::assertFalse($transaction->isActive());
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

    public function testConnectionCloseReleasesTransactionOperationWaitingForResult(): void
    {
        $transaction = $this->connection->beginTransaction();
        $result = $transaction->query('SELECT 1 UNION ALL SELECT 2');
        $future = async(fn () => $transaction->query('SELECT 3'));
        delay(0);

        self::assertFalse($future->isComplete());
        $this->connection->close();
        delay(0);

        self::assertTrue($future->isComplete());
        self::assertTrue($result->isClosed());
        self::assertFalse($transaction->isActive());

        $this->expectException(\Fabpot\Amp\Sqlite\SqliteTransactionError::class);
        $future->await();
    }

    public function testConnectionCloseReleasesOperationWaitingForTransaction(): void
    {
        $transaction = $this->connection->beginTransaction();
        $future = async(fn () => $this->connection->query('SELECT 1'));
        delay(0);

        self::assertFalse($future->isComplete());
        $this->connection->close();
        delay(0);

        self::assertTrue($future->isComplete());
        self::assertFalse($transaction->isActive());

        $this->expectException(\Fabpot\Amp\Sqlite\SqliteConnectionException::class);
        $future->await();
    }

    public function testBeginTransactionFailsOnClosedConnection(): void
    {
        $this->connection->close();

        $this->expectException(\Fabpot\Amp\Sqlite\SqliteConnectionException::class);
        $this->expectExceptionMessage('The SQLite connection is closed');

        $this->connection->beginTransaction();
    }

    public function testAbandonedTransactionRollsBackAndReleasesConnection(): void
    {
        (function (): void {
            $transaction = $this->connection->beginTransaction();
            $transaction->execute('INSERT INTO entries VALUES (?)', ['abandoned']);
        })();

        self::assertSame([], \iterator_to_array($this->connection->query('SELECT value FROM entries')));
    }

    public function testAbandonedNestedTransactionRollsBackAndReleasesParent(): void
    {
        $transaction = $this->connection->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['outer']);

        (function () use ($transaction): void {
            $nested = $transaction->beginTransaction();
            $nested->execute('INSERT INTO entries VALUES (?)', ['abandoned']);
        })();

        $query = async(fn () => \iterator_to_array($transaction->query('SELECT value FROM entries')));

        self::assertSame([['value' => 'outer']], $query->await(new TimeoutCancellation(5)));
        $transaction->commit();
    }

    public function testAbandonedTransactionWithOpenStatementRollsBackAndReleasesConnection(): void
    {
        $statement = (function (): SqliteStatement {
            $transaction = $this->connection->beginTransaction();
            $transaction->execute('INSERT INTO entries VALUES (?)', ['abandoned']);

            return $transaction->prepare('SELECT 1');
        })();

        $query = async(fn () => \iterator_to_array($this->connection->query('SELECT value FROM entries')));

        self::assertSame([], $query->await(new TimeoutCancellation(5)));
        self::assertTrue($statement->isClosed());
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
