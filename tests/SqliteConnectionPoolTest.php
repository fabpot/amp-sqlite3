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
use Fabpot\Amp\Sqlite\SqliteConnectionException;
use Fabpot\Amp\Sqlite\SqliteConnectionPool;
use Fabpot\Amp\Sqlite\SqliteException;
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
        $this->expectException(\InvalidArgumentException::class);

        new SqliteConnectionPool(new SqliteConfig(':memory:'));
    }

    public function testRejectsInvalidPoolLimits(): void
    {
        try {
            new SqliteConnectionPool(new SqliteConfig($this->path), maxConnections: 0);
            self::fail('Expected the invalid connection limit to fail');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Pool must contain at least one connection', $exception->getMessage());
        }

        try {
            new SqliteConnectionPool(new SqliteConfig($this->path), idleTimeout: 0);
            self::fail('Expected the invalid idle timeout to fail');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The idle timeout must be 1 or greater', $exception->getMessage());
        }
    }

    public function testClosedPoolRejectsOperationsWithConnectionException(): void
    {
        $this->pool->close();

        $operations = [
            fn () => $this->pool->query('SELECT 1'),
            fn () => $this->pool->execute('SELECT ?', [1]),
            fn () => $this->pool->prepare('SELECT 1'),
            fn () => $this->pool->beginTransaction(),
            fn () => $this->pool->executeScript('SELECT 1'),
            fn () => $this->pool->openBlob('entries', 'value', 1),
            fn () => $this->pool->backup($this->path . '.backup'),
            fn () => $this->pool->restore($this->path . '.backup'),
            fn () => $this->pool->extractConnection(),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Expected the closed pool operation to fail');
            } catch (SqliteConnectionException $exception) {
                self::assertSame('The SQLite connection pool is closed', $exception->getMessage());
            }
        }
    }

    public function testClosingPoolRejectsWaitingOperationWithConnectionException(): void
    {
        $pool = new SqliteConnectionPool((new SqliteConfig($this->path))->withBatchSize(1), maxConnections: 1);

        try {
            $result = $pool->query('SELECT 1 UNION ALL SELECT 2');
            $waiting = async(fn () => $pool->query('SELECT 3'));
            delay(0);
            self::assertFalse($waiting->isComplete());

            $pool->close();

            $this->expectException(SqliteConnectionException::class);
            $waiting->await();
        } finally {
            $result?->close();
            $pool->close();
        }
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
        $this->expectException(\InvalidArgumentException::class);

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

    public function testScriptsRunOnPooledConnections(): void
    {
        $this->pool->executeScript(<<<'SQL'
            INSERT INTO entries VALUES ('first');
            INSERT INTO entries VALUES ('second');
            SQL);

        self::assertSame(
            [['value' => 'first'], ['value' => 'second']],
            \iterator_to_array($this->pool->query('SELECT value FROM entries ORDER BY rowid')),
        );
    }

    public function testScriptUsesThePoolTransactionMode(): void
    {
        $this->pool->setTransactionIsolation(SqliteTransactionMode::Immediate);
        $this->pool->executeScript("INSERT INTO entries VALUES ('created');");

        $connection = $this->pool->extractConnection();
        try {
            self::assertSame(SqliteTransactionMode::Immediate, $connection->getTransactionIsolation());
        } finally {
            $connection->close();
        }
    }

    public function testFailedScriptDoesNotReturnATaintedConnectionToThePool(): void
    {
        try {
            $this->pool->executeScript(<<<'SQL'
                INSERT INTO entries VALUES ('rolled back');
                INSERT INTO missing_table VALUES ('failed');
                SQL);
            self::fail('Expected the invalid statement to fail');
        } catch (SqliteQueryError) {
        }

        $this->pool->execute('INSERT INTO entries VALUES (?)', ['committed']);
        self::assertSame([['value' => 'committed']], \iterator_to_array($this->pool->query('SELECT value FROM entries')));
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
            $this->pool->execute('SELECT 1', ['s3cr3t-password']);
            self::fail('Expected the invalid parameter to fail');
        } catch (SqliteQueryError $error) {
            self::assertStringNotContainsString('s3cr3t', \var_export($error->getTrace(), true));
        }
    }

    public function testStatementRedactsParameterValuesFromExceptionTraces(): void
    {
        $statement = $this->pool->prepare('SELECT 1');

        try {
            $statement->execute(['s3cr3t-password']);
            self::fail('Expected the invalid parameter to fail');
        } catch (SqliteQueryError $error) {
            self::assertStringNotContainsString('s3cr3t', \var_export($error->getTrace(), true));
        }
    }

    public function testTransactionRedactsParameterValuesFromExceptionTraces(): void
    {
        $transaction = $this->pool->beginTransaction();

        try {
            $transaction->execute('SELECT 1', ['s3cr3t-password']);
            self::fail('Expected the invalid parameter to fail');
        } catch (SqliteQueryError $error) {
            self::assertStringNotContainsString('s3cr3t', \var_export($error->getTrace(), true));
        } finally {
            $transaction->rollback();
        }
    }

    public function testTransactionStatementRedactsParameterValuesFromExceptionTraces(): void
    {
        $transaction = $this->pool->beginTransaction();
        $statement = $transaction->prepare('SELECT 1');

        try {
            $statement->execute(['s3cr3t-password']);
            self::fail('Expected the invalid parameter to fail');
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

    public function testFinishingTransactionClosesPreparedStatementAndReleasesConnection(): void
    {
        $pool = new SqliteConnectionPool(new SqliteConfig($this->path), maxConnections: 1);

        try {
            $transaction = $pool->beginTransaction();
            $statement = $transaction->prepare('SELECT 1');
            $transaction->commit();
            delay(0);

            self::assertTrue($statement->isClosed());
            self::assertSame(['answer' => 42], $pool->query('SELECT 42 AS answer')->fetchRow());
        } finally {
            $pool->close();
        }
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

        try {
            $statement = $pool->prepare('INSERT INTO entries VALUES (?)');
            $result = $statement->execute(['value']);
            unset($result);
            \gc_collect_cycles();
            delay(0);

            $statement->close();
            delay(0);

            self::assertSame([['value' => 'value']], \iterator_to_array($pool->query('SELECT value FROM entries')));
        } finally {
            $pool->close();
        }
    }

    public function testClosingPreparedStatementWithActiveResultReleasesConnectionAfterResultCloses(): void
    {
        $pool = new SqliteConnectionPool((new SqliteConfig($this->path))->withBatchSize(1), maxConnections: 1);
        $result = null;

        try {
            $pool->query("INSERT INTO entries VALUES ('first'), ('second')");
            $statement = $pool->prepare('SELECT value FROM entries ORDER BY value');
            $result = $statement->execute();
            self::assertSame(['value' => 'first'], $result->fetchRow());

            $statement->close();
            $query = async(fn () => $pool->query('SELECT 42 AS answer'));
            delay(0.05);
            self::assertFalse($query->isComplete());

            $result->close();
            self::assertSame(['answer' => 42], $query->await()->fetchRow());
        } finally {
            $result?->close();
            $pool->close();
        }
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

    public function testPooledFetchAfterExplicitResultCloseFails(): void
    {
        $result = $this->pool->query('SELECT 1');
        $result->close();

        $this->expectException(SqliteException::class);
        $this->expectExceptionMessage('The SQLite result is closed');

        $result->fetchRow();
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
