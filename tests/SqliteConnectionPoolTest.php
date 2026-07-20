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
use Fabpot\Amp\Sqlite\SqliteConnectionPool;
use Fabpot\Amp\Sqlite\SqliteQueryError;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

final class SqliteConnectionPoolTest extends TestCase
{
    private string $path;
    private SqliteConnectionPool $pool;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/amp-sqlite-pool-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $this->pool = new SqliteConnectionPool(new SqliteConfig($this->path));
        $this->pool->query('CREATE TABLE entries (value TEXT)')->close();
    }

    protected function tearDown(): void
    {
        $this->pool->close();
        @\unlink($this->path);
        @\unlink($this->path . '-shm');
        @\unlink($this->path . '-wal');
    }

    public function testRejectsMemoryDatabases(): void
    {
        $this->expectException(\RuntimeException::class);

        new SqliteConnectionPool(new SqliteConfig(':memory:'));
    }

    public function testUsesConfiguredTransactionModeByDefault(): void
    {
        $pool = new SqliteConnectionPool(
            (new SqliteConfig($this->path))->withTransactionMode(SqliteTransactionMode::Immediate),
        );

        try {
            $transaction = $pool->beginTransaction();
            self::assertSame(SqliteTransactionMode::Immediate, $transaction->getIsolation());
            $transaction->rollback();
        } finally {
            $pool->close();
        }
    }

    public function testExplicitTransactionModeOverridesConfig(): void
    {
        $pool = new SqliteConnectionPool(
            (new SqliteConfig($this->path))->withTransactionMode(SqliteTransactionMode::Immediate),
            transactionIsolation: SqliteTransactionMode::Exclusive,
        );

        try {
            $transaction = $pool->beginTransaction();
            self::assertSame(SqliteTransactionMode::Exclusive, $transaction->getIsolation());
            $transaction->rollback();
        } finally {
            $pool->close();
        }
    }

    public function testRejectsGenericIsolationLevel(): void
    {
        $this->expectException(\TypeError::class);

        $this->pool->setTransactionIsolation(SqlTransactionIsolationLevel::Serializable);
    }

    public function testQueriesRunOnPooledConnections(): void
    {
        $this->pool->execute('INSERT INTO entries VALUES (?)', ['pooled']);

        self::assertSame(
            [['value' => 'pooled']],
            \iterator_to_array($this->pool->query('SELECT value FROM entries')),
        );
        self::assertGreaterThan(0, $this->pool->getConnectionCount());
    }

    public function testCommandResultImmediatelyReleasesItsConnection(): void
    {
        $pool = new SqliteConnectionPool(new SqliteConfig($this->path), maxConnections: 1);

        try {
            $command = $pool->execute('INSERT INTO entries VALUES (?)', ['first']);
            $result = async(fn () => $pool->execute('INSERT INTO entries VALUES (?)', ['second']));

            self::assertTrue($command->isClosed());
            self::assertSame(1, $pool->getIdleConnectionCount());
            $result->await();
            self::assertSame(2, $pool->query('SELECT COUNT(*) AS count FROM entries')->fetchRow()['count']);
        } finally {
            $pool->close();
        }
    }

    public function testExecuteRedactsParameterValuesFromExceptionTraces(): void
    {
        try {
            $this->pool->execute('SELECT ?, ?', ['s3cr3t-password']);
            self::fail('Expected the parameter count mismatch to fail');
        } catch (SqliteQueryError $error) {
            self::assertStringNotContainsString('s3cr3t', \var_export($error->getTrace(), true));
        }
    }

    public function testStatementRedactsParameterValuesFromExceptionTraces(): void
    {
        $statement = $this->pool->prepare('SELECT ?, ?');

        try {
            $statement->execute(['s3cr3t-password']);
            self::fail('Expected the parameter count mismatch to fail');
        } catch (SqliteQueryError $error) {
            self::assertStringNotContainsString('s3cr3t', \var_export($error->getTrace(), true));
        }
    }

    public function testTransactionRedactsParameterValuesFromExceptionTraces(): void
    {
        $transaction = $this->pool->beginTransaction();

        try {
            $transaction->execute('SELECT ?, ?', ['s3cr3t-password']);
            self::fail('Expected the parameter count mismatch to fail');
        } catch (SqliteQueryError $error) {
            self::assertStringNotContainsString('s3cr3t', \var_export($error->getTrace(), true));
        } finally {
            $transaction->rollback();
        }
    }

    public function testTransactionStatementRedactsParameterValuesFromExceptionTraces(): void
    {
        $transaction = $this->pool->beginTransaction();
        $statement = $transaction->prepare('SELECT ?, ?');

        try {
            $statement->execute(['s3cr3t-password']);
            self::fail('Expected the parameter count mismatch to fail');
        } catch (SqliteQueryError $error) {
            self::assertStringNotContainsString('s3cr3t', \var_export($error->getTrace(), true));
        } finally {
            $transaction->rollback();
        }
    }

    public function testConcurrentQueriesUseSeparateConnections(): void
    {
        $this->pool->execute('INSERT INTO entries VALUES (?)', ['row']);

        $first = async(fn () => $this->pool->query('SELECT value FROM entries UNION ALL SELECT value FROM entries'));
        $second = async(fn () => $this->pool->query('SELECT value FROM entries'));

        $firstResult = $first->await();
        $secondResult = $second->await();

        self::assertCount(1, \iterator_to_array($secondResult));
        self::assertCount(2, \iterator_to_array($firstResult));
        self::assertGreaterThanOrEqual(2, $this->pool->getConnectionCount());
    }

    public function testTransactionOwnsItsConnectionUntilFinished(): void
    {
        $transaction = $this->pool->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['in transaction']);

        self::assertSame([], \iterator_to_array($this->pool->query('SELECT value FROM entries')));

        $transaction->commit();

        self::assertSame(
            [['value' => 'in transaction']],
            \iterator_to_array($this->pool->query('SELECT value FROM entries')),
        );
    }

    public function testNestedTransactionsOnPooledConnection(): void
    {
        $transaction = $this->pool->beginTransaction();
        $nested = $transaction->beginTransaction();
        $nested->execute('INSERT INTO entries VALUES (?)', ['nested']);
        $nested->rollback();
        $transaction->commit();

        self::assertSame([], \iterator_to_array($this->pool->query('SELECT value FROM entries')));
    }

    public function testPreparedStatementsSurviveAcrossConnections(): void
    {
        $statement = $this->pool->prepare('INSERT INTO entries VALUES (?)');
        $statement->execute(['first']);
        $statement->execute(['second']);

        self::assertSame(
            [['value' => 'first'], ['value' => 'second']],
            \iterator_to_array($this->pool->query('SELECT value FROM entries ORDER BY value')),
        );
    }

    public function testPreparedStatementCommandResultCanBeClosed(): void
    {
        $statement = $this->pool->prepare('INSERT INTO entries VALUES (?)');
        $result = $statement->execute(['value']);

        $result->close();

        self::assertSame([['value' => 'value']], \iterator_to_array($this->pool->query('SELECT value FROM entries')));
    }

    public function testClosingPreparedStatementReleasesCachedConnections(): void
    {
        $pool = new SqliteConnectionPool(new SqliteConfig($this->path), maxConnections: 1);
        $statement = $pool->prepare('INSERT INTO entries VALUES (?)');
        $result = $statement->execute(['value']);
        unset($result);
        \gc_collect_cycles();
        delay(0);

        $statement->close();
        delay(0);

        self::assertSame([['value' => 'value']], \iterator_to_array($pool->query('SELECT value FROM entries')));
        $pool->close();
    }

    public function testOpenBlobReleasesConnectionOnClose(): void
    {
        $rowId = $this->pool->query('INSERT INTO entries VALUES (zeroblob(3))')->getLastInsertId();

        $blob = $this->pool->openBlob('entries', 'value', $rowId);
        $idleBefore = $this->pool->getIdleConnectionCount();
        $blob->close();
        delay(0);

        self::assertSame($idleBefore + 1, $this->pool->getIdleConnectionCount());
    }

    public function testExtractConnectionRemovesItFromThePool(): void
    {
        $countBefore = $this->pool->getConnectionCount();
        $connection = $this->pool->extractConnection();

        try {
            self::assertLessThan($countBefore + 1, $this->pool->getConnectionCount());
            self::assertSame(['answer' => 42], $connection->query('SELECT 42 AS answer')->fetchRow());
        } finally {
            $connection->close();
        }
    }

    public function testBackupRunsOnPooledConnection(): void
    {
        $this->pool->execute('INSERT INTO entries VALUES (?)', ['backed up']);
        $backupPath = $this->path . '.backup';

        try {
            $this->pool->backup($backupPath);

            $copy = new \SQLite3($backupPath);
            self::assertSame(1, $copy->querySingle('SELECT COUNT(*) FROM entries'));
            $copy->close();
        } finally {
            @\unlink($backupPath);
        }
    }

    public function testPooledResultExposesMetadata(): void
    {
        $insert = $this->pool->execute('INSERT INTO entries VALUES (?)', ['row']);

        self::assertSame(1, $insert->getRowCount());
        self::assertSame(1, $insert->getLastInsertId());

        $rows = $this->pool->query('SELECT value FROM entries');

        self::assertSame(['value'], $rows->getColumnNames());
        self::assertSame(1, $rows->getColumnCount());
    }
}
